<?php

namespace App\Services\Fiscal;

use App\Models\Invoice;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Smart POS fiscalises the existing issued outgoing invoice. It never creates a second invoice.
 * Device I/O runs outside database transactions; the invoice is marked pending first so a
 * concurrent request cannot print the same receipt twice.
 */
class SmartPos
{
    public const REPORTS = [
        'daily' => ['fiscal:dnevni-izvjestaj', '0'],
        'snapshot' => ['fiscal:presjek-stanja', '2'],
    ];

    public function __construct(private FiscalBridge $bridge, private AccountingLedger $ledger) {}

    public function status(): array
    {
        if (! $this->bridge->configured()) {
            return ['ok' => true, 'online' => false, 'installed' => false, 'reason' => 'Fiscal driver worker is not installed.', 'checkedAt' => now()->toIso8601String()];
        }
        try {
            $payload = $this->bridge->run(['fiscal:probe'], 30);
        } catch (ValidationException $e) {
            return ['ok' => true, 'online' => false, 'installed' => true, 'reason' => collect($e->errors())->flatten()->first(), 'checkedAt' => now()->toIso8601String()];
        }
        $candidates = is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [];
        $connection = is_array($payload['likelyConnection'] ?? null) ? $payload['likelyConnection']
            : collect($candidates)->first(fn ($c) => is_array($c) && ($c['reachable'] ?? false) === true);

        return ['ok' => true, 'installed' => true, 'online' => ($connection['reachable'] ?? false) === true,
            'checkedAt' => $payload['timestamp'] ?? now()->toIso8601String(), 'port' => $connection['port'] ?? null,
            'name' => $connection['name'] ?? null, 'reason' => $connection['reason'] ?? null];
    }

    public function receipt(Invoice $invoice, string $paymentMethod): array
    {
        $snapshot = $invoice->issued_snapshot ?? [];
        $labels = config('fiscal.tax_labels', []);
        $lines = [];
        $total = '0.00';
        foreach ($snapshot['items'] ?? [] as $item) {
            $rate = $item['tax_rate'] ?? null;
            $this->ledger->require($rate !== null && isset($labels[Decimal::value((string) $rate, 4)]), 'No fiscal tax label is configured for VAT rate '.($rate ?? 'unknown').'.');
            $quantity = Decimal::value((string) $item['quantity'], 3);
            $lineTotal = Decimal::value((string) $item['total'], 2);
            $gross = bcadd($lineTotal, Decimal::value((string) ($item['tax_amount'] ?? '0'), 2), 2);
            $lines[] = ['name' => mb_substr((string) $item['description'], 0, 32), 'quantity' => $quantity,
                'price' => bccomp($quantity, '0', 3) === 0 ? '0.00' : Decimal::value(bcdiv($gross, $quantity, 6), 2),
                'total' => $gross, 'tax_label' => $labels[Decimal::value((string) $rate, 4)], 'tax_rate' => Decimal::value((string) $rate, 2)];
            $total = bcadd($total, $gross, 2);
        }
        $this->ledger->require($lines !== [], 'The issued invoice has no items to fiscalise.');
        $buyer = $snapshot['buyer'] ?? [];

        return ['invoice_number' => $invoice->number, 'operator' => (string) config('fiscal.operator', '1'), 'currency' => $invoice->currency,
            'payment' => ['method' => $paymentMethod, 'amount' => $total], 'total' => $total, 'lines' => $lines,
            'buyer' => ['name' => $buyer['name'] ?? $invoice->partner_name, 'tax_number' => $buyer['tax_number'] ?? $invoice->partner_tax_number,
                'address' => $buyer['address'] ?? null]];
    }

    public function fiscalise(int $companyId, int $actor, int $invoiceId, string $paymentMethod, string $requestKey): Invoice
    {
        $this->ledger->require(in_array($paymentMethod, config('fiscal.payment_methods', []), true), 'Unsupported payment method.');
        [$operationId, $request] = DB::transaction(function () use ($companyId, $actor, $invoiceId, $paymentMethod, $requestKey) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $existing = DB::table('accounting_fiscal_operations')->where('company_id', $companyId)->where('request_key', $requestKey)->first();
            if ($existing) {
                return [null, null];
            }
            $invoice = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->lockForUpdate()->findOrFail($invoiceId);
            $this->ledger->require($invoice->direction === 'outgoing' && $invoice->issuance_status === 'issued', 'Only an issued outgoing invoice can be fiscalised.');
            $this->ledger->require(! $invoice->corrects_invoice_id, 'A corrective invoice is fiscalised as a refund of the original receipt.');
            $this->ledger->require(in_array($invoice->fiscal_status, ['none', 'failed'], true), 'This invoice already has a fiscal receipt or one is in progress.');
            $this->ledger->require($invoice->currency === 'BAM', 'The fiscal device accepts BAM receipts only.');
            $request = $this->receipt($invoice, $paymentMethod);
            $invoice->fiscal_status = 'pending';
            $invoice->fiscal_payment_method = $paymentMethod;
            $invoice->save();
            $id = $this->operation($companyId, $actor, $invoiceId, 'receipt', $requestKey, 'fiscal:fiskalni-racun', $request);

            return [$id, $request];
        });
        if ($operationId === null) {
            return Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
        }

        return $this->dispatch($companyId, $actor, $invoiceId, $operationId, 'fiscal:fiskalni-racun', $request, function (Invoice $invoice, array $response) {
            $invoice->fiscal_status = 'fiscalised';
            $invoice->fiscal_number = $this->fiscalNumber($response);
            $invoice->fiscalised_at = now();
            $invoice->fiscal_device = $response['bridgeSettings']['model'] ?? $response['device'] ?? null;
        }, 'receipt_fiscalised');
    }

    public function refund(int $companyId, int $actor, int $invoiceId, string $reason, string $requestKey): Invoice
    {
        [$operationId, $request] = DB::transaction(function () use ($companyId, $actor, $invoiceId, $reason, $requestKey) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            if (DB::table('accounting_fiscal_operations')->where('company_id', $companyId)->where('request_key', $requestKey)->exists()) {
                return [null, null];
            }
            $invoice = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->lockForUpdate()->findOrFail($invoiceId);
            $this->ledger->require($invoice->fiscal_status === 'fiscalised' && $invoice->fiscal_number, 'Only a fiscalised receipt can be refunded.');
            $request = $this->receipt($invoice, (string) $invoice->fiscal_payment_method) + ['original_fiscal_number' => (int) $invoice->fiscal_number,
                'original_fiscalised_at' => $invoice->fiscalised_at?->toIso8601String(), 'reason' => $reason];
            $invoice->fiscal_status = 'refund_pending';
            $invoice->save();

            return [$this->operation($companyId, $actor, $invoiceId, 'refund', $requestKey, 'fiscal:reklamirani-racun', $request), $request];
        });
        if ($operationId === null) {
            return Invoice::where('company_id', $companyId)->findOrFail($invoiceId);
        }

        return $this->dispatch($companyId, $actor, $invoiceId, $operationId, 'fiscal:reklamirani-racun', $request, function (Invoice $invoice, array $response) {
            $invoice->fiscal_status = 'refunded';
            $invoice->fiscal_refund_number = $this->fiscalNumber($response);
            $invoice->fiscal_refunded_at = now();
        }, 'receipt_refunded', 'fiscalised');
    }

    /** Records a receipt the device printed but did not confirm (timeout), using the number on the paper slip. */
    public function confirm(int $companyId, int $actor, int $invoiceId, int $fiscalNumber): Invoice
    {
        return DB::transaction(function () use ($companyId, $actor, $invoiceId, $fiscalNumber) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $invoice = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->lockForUpdate()->findOrFail($invoiceId);
            $this->ledger->require(in_array($invoice->fiscal_status, ['unconfirmed', 'refund_unconfirmed'], true), 'Only an unconfirmed receipt can be confirmed manually.');
            $this->ledger->require($fiscalNumber > 0, 'Enter the fiscal number printed on the receipt.');
            if ($invoice->fiscal_status === 'unconfirmed') {
                $this->ledger->require(! Invoice::where('company_id', $companyId)->where('fiscal_number', $fiscalNumber)->where('id', '!=', $invoiceId)->exists(), 'This fiscal number is already linked to another invoice.');
                $invoice->fiscal_status = 'fiscalised';
                $invoice->fiscal_number = $fiscalNumber;
                $invoice->fiscalised_at = now();
            } else {
                $invoice->fiscal_status = 'refunded';
                $invoice->fiscal_refund_number = $fiscalNumber;
                $invoice->fiscal_refunded_at = now();
            }
            $invoice->save();
            $this->ledger->audit($companyId, $actor, 'invoice', $invoiceId, 'fiscal_confirmed_manually', ['fiscal_number' => $fiscalNumber]);

            return $invoice;
        });
    }

    public function report(int $companyId, int $actor, string $type, array $data): array
    {
        $timeout = (int) config('fiscal.timeout', 90);
        if (isset(self::REPORTS[$type])) {
            [$command, $option] = self::REPORTS[$type];
            $args = [$command, '--report-type='.($data['report_type'] ?? $option), '--timeout='.$timeout];
        } elseif ($type === 'periodic') {
            $command = 'fiscal:periodicni-izvjestaj';
            $args = [$command, '--from='.$data['from'], '--to='.$data['to'], '--timeout='.$timeout];
        } elseif ($type === 'duplicate') {
            $invoice = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->findOrFail((int) ($data['invoice_id'] ?? 0));
            $this->ledger->require(in_array($invoice->fiscal_status, ['fiscalised', 'refunded'], true) && $invoice->fiscal_number > 0, 'The invoice has no valid fiscal number for a duplicate.');
            $command = 'fiscal:stampaj-duplikat';
            $args = [$command, '--fiscal-number='.$invoice->fiscal_number, '--timeout='.$timeout];
        } else {
            $command = 'fiscal:test-paper';
            $lines = array_values(array_filter(array_map(fn ($l) => mb_substr(trim((string) $l), 0, 42), $data['lines'] ?? []), 'strlen'));
            $this->ledger->require($lines !== [], 'Non-fiscal text needs at least one line.');
            $args = [$command, '--timeout=60', ...array_map(fn ($l) => '--line='.$l, $lines)];
        }
        if (! empty($data['preview'])) {
            $args[] = '--preview';
        }
        $operationId = $this->operation($companyId, $actor, isset($invoice) ? $invoice->id : null, $type, $data['request_key'], $command, ['args' => $args]);
        try {
            $response = $this->bridge->run($args, $timeout);
            DB::table('accounting_fiscal_operations')->where('id', $operationId)->update(['status' => 'ok', 'response' => json_encode($response), 'updated_at' => now()]);

            return ['operation_id' => $operationId, 'command' => $command] + $response;
        } catch (ValidationException|ProcessTimedOutException $e) {
            DB::table('accounting_fiscal_operations')->where('id', $operationId)->update(['status' => 'failed', 'error' => $this->message($e), 'updated_at' => now()]);
            throw $e instanceof ValidationException ? $e : ValidationException::withMessages(['fiscal' => $this->message($e)]);
        }
    }

    /** Non-fiscal summaries (by item, by payment method) built from fiscalised invoices in the period. */
    public function summary(int $companyId, string $from, string $to): array
    {
        $invoices = Invoice::where('company_id', $companyId)->where('accounting_managed', true)->whereIn('fiscal_status', ['fiscalised', 'refunded'])
            ->whereBetween('fiscalised_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $items = [];
        $payments = [];
        foreach ($invoices as $invoice) {
            $sign = $invoice->fiscal_status === 'refunded' ? '0' : '1';
            $method = $invoice->fiscal_payment_method ?: 'unknown';
            $payments[$method] = bcadd($payments[$method] ?? '0.00', bcmul((string) $invoice->total, $sign, 2), 2);
            foreach ($invoice->issued_snapshot['items'] ?? [] as $item) {
                $key = (string) $item['description'];
                $items[$key] ??= ['description' => $key, 'quantity' => '0.000', 'total' => '0.00'];
                $items[$key]['quantity'] = bcadd($items[$key]['quantity'], bcmul(Decimal::value((string) $item['quantity'], 3), $sign, 3), 3);
                $items[$key]['total'] = bcadd($items[$key]['total'], bcmul(bcadd(Decimal::value((string) $item['total'], 2), Decimal::value((string) ($item['tax_amount'] ?? '0'), 2), 2), $sign, 2), 2);
            }
        }

        return ['from' => $from, 'to' => $to, 'receipts' => $invoices->count(), 'by_item' => array_values($items),
            'by_payment' => collect($payments)->map(fn ($total, $method) => ['payment_method' => $method, 'total' => $total])->values()->all()];
    }

    private function dispatch(int $companyId, int $actor, int $invoiceId, int $operationId, string $command, array $request, callable $success, string $audit, ?string $failedStatus = 'failed'): Invoice
    {
        $pending = $failedStatus === 'fiscalised' ? 'refund_' : '';
        try {
            $response = $this->bridge->runWithRequest($command, $request);
            $status = 'ok';
            $error = null;
        } catch (ValidationException $e) {
            // The worker answered with an error: nothing was printed, so the action can be retried.
            [$response, $status, $error] = [null, 'failed', $this->message($e)];
        } catch (ProcessTimedOutException $e) {
            // The device may have printed. Require manual confirmation instead of risking a second receipt.
            [$response, $status, $error] = [null, 'unconfirmed', $this->message($e)];
        }

        return DB::transaction(function () use ($companyId, $actor, $invoiceId, $operationId, $response, $status, $error, $success, $audit, $failedStatus, $pending) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $invoice = Invoice::where('company_id', $companyId)->lockForUpdate()->findOrFail($invoiceId);
            if ($status === 'ok' && $this->fiscalNumber($response) === null) {
                [$status, $error] = ['unconfirmed', 'The device did not return a fiscal number.'];
            }
            if ($status === 'ok') {
                $success($invoice, $response);
            } else {
                $invoice->fiscal_status = $status === 'unconfirmed' ? $pending.'unconfirmed' : $failedStatus;
            }
            $invoice->save();
            DB::table('accounting_fiscal_operations')->where('id', $operationId)->update(['status' => $status,
                'response' => $response === null ? null : json_encode($response), 'error' => $error, 'updated_at' => now()]);
            $this->ledger->audit($companyId, $actor, 'invoice', $invoiceId, $status === 'ok' ? $audit : 'fiscal_'.$status, ['operation_id' => $operationId]);

            return $invoice;
        });
    }

    private function operation(int $companyId, int $actor, ?int $invoiceId, string $operation, string $requestKey, string $command, array $payload): int
    {
        $this->ledger->require(! DB::table('accounting_fiscal_operations')->where('company_id', $companyId)->where('request_key', $requestKey)->exists(), 'This fiscal request was already sent.');

        return DB::table('accounting_fiscal_operations')->insertGetId(['company_id' => $companyId, 'invoice_id' => $invoiceId, 'operation' => $operation,
            'status' => 'pending', 'request_key' => $requestKey, 'command' => $command, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'requested_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fiscalNumber(?array $response): ?int
    {
        $value = $response['fiscalNumber'] ?? $response['payload']['fiscalNumber'] ?? $response['response']['fiscalNumber'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
    }
}
