<?php

namespace App\Services\Accounting;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class AccountingPayments
{
    public function __construct(private AccountingLedger $ledger) {}

    public function allocate(int $companyId, int $actor, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $data) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $existing = DB::table('accounting_payment_allocations')->where('company_id', $companyId)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->ledger->require((int) $existing->invoice_id === (int) $data['invoice_id']
                    && (int) $existing->bank_transaction_id === (int) $data['bank_transaction_id']
                    && bccomp((string) $existing->amount, Decimal::value($data['amount']), 2) === 0, 'Request key was used for a different allocation.');

                return $existing;
            }
            $bank = DB::table('accounting_bank_transactions')->where('company_id', $companyId)->where('id', $data['bank_transaction_id'])->lockForUpdate()->first();
            $invoice = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->lockForUpdate()->findOrFail($data['invoice_id']);
            $entry = DB::table('accounting_entries')->where('company_id', $companyId)->where('event_key', 'invoice:'.$invoice->id)->first();
            $this->ledger->require($bank && $entry, 'Bank transaction and a posted invoice are required.');
            $this->ledger->require(! $invoice->corrects_invoice_id && ! DB::table('accounting_entries')->where('company_id', $companyId)
                ->where('reverses_entry_id', $entry->id)->exists(), 'A corrected invoice cannot receive new allocations.');
            $this->ledger->require($bank->currency === $invoice->currency && $bank->direction === ($invoice->direction === 'incoming' ? 'outgoing' : 'incoming')
                && (int) $bank->partner_id === (int) $invoice->partner_id, 'Payment currency, direction and partner must match the invoice.');
            $amount = Decimal::value($data['amount']);
            $this->ledger->require(bccomp($amount, '0', 2) > 0, 'Allocation amount must be positive.');
            $bankUsed = $this->allocated('bank_transaction_id', $bank->id);
            $invoiceUsed = $this->allocated('invoice_id', $invoice->id);
            $this->ledger->require(bccomp(bcadd($bankUsed, $amount, 2), (string) $bank->amount, 2) <= 0, 'Allocation exceeds available bank amount.');
            $this->ledger->require(bccomp(bcadd($invoiceUsed, $amount, 2), (string) $invoice->total, 2) <= 0, 'Allocation exceeds invoice balance.');
            $originalLines = DB::table('accounting_entry_lines')->where('entry_id', $entry->id)->whereNull('invoice_item_id')->get();
            $control = $originalLines->last();
            $this->ledger->require($control !== null, 'Invoice control account is missing.');
            // Prorate the actual control amount. The final payment takes the rounding remainder.
            $controlTotal = $invoice->direction === 'incoming' ? $control->credit : $control->debit;
            $before = Decimal::round(bcmul(bcdiv($invoiceUsed, (string) $invoice->total, 12), (string) $controlTotal, 12));
            $after = Decimal::round(bcmul(bcdiv(bcadd($invoiceUsed, $amount, 2), (string) $invoice->total, 12), (string) $controlTotal, 12));
            $bookAmount = bcsub($after, $before, 2);
            $bankAmount = Decimal::convert($amount, (string) $bank->exchange_rate);
            $advance = DB::table('accounting_advances')->where('company_id', $companyId)->where('bank_transaction_id', $bank->id)->first();
            $outgoing = $invoice->direction === 'incoming';
            $lines = [
                ['account_id' => $control->account_id, 'debit' => $outgoing ? $bookAmount : '0', 'credit' => $outgoing ? '0' : $bookAmount],
                ['account_id' => $advance?->control_account_id ?? $data['bank_account_id'], 'debit' => $outgoing ? '0' : $bankAmount, 'credit' => $outgoing ? $bankAmount : '0'],
            ];
            $difference = bcsub($bankAmount, $bookAmount, 2);
            if (bccomp($difference, '0', 2) !== 0) {
                $loss = ($outgoing && bccomp($difference, '0', 2) > 0) || (! $outgoing && bccomp($difference, '0', 2) < 0);
                $key = $loss ? 'fx_loss_account_id' : 'fx_gain_account_id';
                $this->ledger->require(! empty($data[$key]), 'Exchange difference account required: '.$key);
                $absolute = ltrim($difference, '-');
                $lines[] = ['account_id' => $data[$key], 'debit' => $loss ? $absolute : '0', 'credit' => $loss ? '0' : $absolute];
            }
            $id = DB::table('accounting_payment_allocations')->insertGetId(['company_id' => $companyId, 'bank_transaction_id' => $bank->id,
                'invoice_id' => $invoice->id, 'amount' => $amount, 'request_key' => $data['request_key'], 'confirmed_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $date = $data['posting_date'] ?? $bank->transaction_date;
            $this->ledger->require($date >= substr((string) $invoice->posting_date, 0, 10), 'Settlement cannot precede invoice posting.');
            $this->ledger->post($companyId, $actor, ['event_key' => 'allocation:'.$id, 'posting_date' => $date,
                'description' => $bank->reference.' / '.$invoice->number, 'lines' => $lines]);
            if ($advance) {
                DB::table('accounting_advances')->where('id', $advance->id)->update(['settled_amount' => bcadd($bankUsed, $amount, 2), 'updated_at' => now()]);
            }
            $this->ledger->audit($companyId, $actor, 'invoice', $invoice->id, 'payment_allocated', ['allocation_id' => $id, 'amount' => $amount]);

            return DB::table('accounting_payment_allocations')->find($id);
        });
    }

    public function allocated(string $column, int $id): string
    {
        return Decimal::sum(DB::table('accounting_payment_allocations')->where($column, $id)->pluck('amount'));
    }
}
