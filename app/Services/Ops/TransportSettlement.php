<?php

namespace App\Services\Ops;

use App\Services\Accounting\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read the existing books. A paid flag on an invoice is deliberately not evidence of settlement. */
final class TransportSettlement
{
    public function read(object $order): array
    {
        $delivered = $order->workspace_id
            ? DB::table('shipment_workspace')->where('id', $order->workspace_id)->value('status') === 'completed'
            : DB::table('ops_events')->where('order_id', $order->id)->where('event_type', 'delivered')->exists();
        $flow = Schema::hasTable('ops_transport_flows') ? DB::table('ops_transport_flows')->where('order_id', $order->id)->first() : null;
        $legs = json_decode($flow?->legs ?? '[]', true);
        if ($legs && collect($legs)->contains(fn ($leg) => ($leg['status'] ?? '') !== 'delivered')) $delivered = false;
        foreach ($legs as $leg) {
            if (($leg['status'] ?? '') === 'delivered' && (empty($leg['document_ids']) || app(\App\Services\OperationalCompliance::class)->documentHashes($leg['document_ids']) !== ($leg['document_hashes'] ?? []))) $delivered = false;
        }
        $invoices = DB::table('invoices')->where('company_id', $order->company_id)->where('direction', 'outgoing')->whereNull('corrects_invoice_id')
            ->where(function ($q) use ($order) {
                if ($order->workspace_id) $q->whereIn('id', DB::table('invoice_items')->where('workspace_id', $order->workspace_id)->select('invoice_id'));
                else $q->whereRaw('1 = 0'); // Unscoped invoice attachments are not an accounting allocation.
            })->orderBy('id')->get();
        $coverage = '0.00'; $currencyMismatch = false;
        $rows = []; $totals = []; $active = 0; $paidAny = false; $allPaid = true; $draft = false;
        foreach ($invoices as $invoice) {
            $entry = DB::table('accounting_entries')->where('company_id', $order->company_id)->where('event_key', 'invoice:'.$invoice->id)->first();
            $corrected = $entry && DB::table('accounting_entries')->where('company_id', $order->company_id)->where('reverses_entry_id', $entry->id)->exists();
            if ($corrected) { $rows[] = ['id' => $invoice->id, 'number' => $invoice->number, 'state' => 'corrected', 'currency' => $invoice->currency]; continue; }
            $active++;
            if ($invoice->currency !== $order->currency) $currencyMismatch = true;
            if ($order->workspace_id) {
                foreach (DB::table('invoice_items')->where('invoice_id',$invoice->id)->where('workspace_id',$order->workspace_id)->get() as $line) $coverage = bcadd($coverage, Decimal::round(bcmul((string) $line->quantity, (string) $line->unit_price, 8)), 2);
            } else $coverage = bcadd($coverage, (string) ($invoice->subtotal ?? $invoice->total), 2);
            $confirmed = $invoice->issuance_status === 'issued' && $entry;
            $draft = $draft || !$confirmed;
            $amount = Decimal::value((string) $invoice->total);
            $paid = '0.00';
            foreach (DB::table('accounting_payment_allocations')->where('company_id', $order->company_id)->where('invoice_id', $invoice->id)->get() as $allocation) {
                $posting = DB::table('accounting_entries')->where('company_id', $order->company_id)->where('event_key', 'allocation:'.$allocation->id)->first();
                if ($posting && !DB::table('accounting_entries')->where('company_id', $order->company_id)->where('reverses_entry_id', $posting->id)->exists()) $paid = bcadd($paid, (string) $allocation->amount, 2);
            }
            $balance = bccomp($paid, $amount, 2) >= 0 ? '0.00' : bcsub($amount, $paid, 2);
            // Zero value work needs an explicit business exception, not an implicit paid state.
            $settled = $confirmed && bccomp($amount, '0', 2) > 0 && bccomp($balance, '0', 2) === 0;
            $allPaid = $allPaid && $settled; $paidAny = $paidAny || bccomp($paid, '0', 2) > 0;
            $currency = $invoice->currency;
            $totals[$currency] ??= ['invoiced' => '0.00', 'allocated' => '0.00', 'balance' => '0.00'];
            foreach (['invoiced' => $amount, 'allocated' => $paid, 'balance' => $balance] as $key => $value) $totals[$currency][$key] = bcadd($totals[$currency][$key], $value, 2);
            $rows[] = ['id' => $invoice->id, 'number' => $invoice->number, 'currency' => $currency, 'total' => $amount, 'allocated' => $paid, 'balance' => $balance,
                'state' => !$confirmed ? 'unposted' : ($settled ? 'paid' : (bccomp($paid, '0', 2) > 0 ? 'partial' : 'unpaid'))];
        }
        $payment = !$active ? 'uninvoiced' : ($draft ? 'unposted' : ($allPaid ? 'paid' : ($paidAny ? 'partial' : 'unpaid')));
        $problems = [];
        if (!$delivered) $problems[] = 'delivery_not_confirmed';
        if ($payment !== 'paid') $problems[] = 'payment_'.$payment;
        if ($currencyMismatch) $problems[] = 'invoice_currency_review';
        if ($order->agreed_revenue !== null && bccomp($coverage, (string) $order->agreed_revenue, 2) < 0) $problems[] = 'incomplete_billing';
        $problems = array_merge($problems, app(\App\Services\OperationalCompliance::class)->blockers($order));
        if ($order->workspace_id && !DB::table('ops_events')->where('order_id',$order->id)->where('event_type','pod')->exists() && !DB::table('ops_order_documents')->where('order_id',$order->id)->where('role','pod')->exists()) $problems[]='missing_pod';
        return ['transport' => $delivered ? 'delivered' : (in_array($order->status, ['in_progress','partially_closed']) ? 'in_progress' : 'planned'),
            'payment' => $payment, 'order' => $order->status === 'cancelled' ? 'cancelled' : ($order->status === 'closed' ? ($problems ? 'review_required' : 'finished') : ($delivered ? 'awaiting_finance' : 'open')),
            'billed_net' => $coverage, 'can_finish' => !$problems && $order->status !== 'cancelled', 'problems' => $problems, 'invoices' => $rows, 'totals' => $totals];
    }

    /** Called inside the accounting transaction, after the common company lock has been acquired. */
    public function reconcile(int $company, int $actor): void
    {
        if (!Schema::hasTable('ops_orders') || !Schema::hasTable('ops_transport_flows')) return;
        foreach (DB::table('ops_orders')->where('company_id', $company)->where('status', 'closed')->whereIn('id', DB::table('ops_transport_flows')->select('order_id'))->orderBy('id')->lockForUpdate()->get() as $order) {
            $state = $this->read($order);
            if ($state['can_finish']) continue;
            DB::table('ops_orders')->where('id', $order->id)->update(['status' => 'partially_closed', 'finished_at' => null,
                'close_problems' => implode("\n", $state['problems']), 'revision' => $order->revision + 1, 'updated_at' => now()]);
            app(\App\Services\Accounting\AccountingLedger::class)->audit($company, $actor, 'ops_order', $order->id, 'ops_finance_reopened', $state);
        }
    }
}
