<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingLedger
{
    public function post(int $companyId, int $actor, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $data) {
            // All accounting operations acquire the same company lock before other locks.
            $settings = DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $this->require($settings !== null, 'Company accounting is not configured.');
            $existing = DB::table('accounting_entries')->where('company_id', $companyId)->where('event_key', $data['event_key'])->first();
            if ($existing) {
                return $existing;
            }
            $period = DB::table('accounting_periods')->where('company_id', $companyId)
                ->where('starts_on', '<=', $data['posting_date'])->where('ends_on', '>=', $data['posting_date'])->lockForUpdate()->first();
            $this->require($period && $period->status === 'open', 'Posting period is missing or locked.');
            $debit = '0.00';
            $credit = '0.00';
            $lines = [];
            foreach ($data['lines'] as $line) {
                $account = DB::table('accounting_accounts')->where('company_id', $companyId)->where('id', $line['account_id'])->first();
                $this->require($account && $account->active && $account->approved_by, 'Account is not approved for this company.');
                $d = Decimal::value($line['debit'] ?? '0');
                $c = Decimal::value($line['credit'] ?? '0');
                $this->require(bccomp($d, '0', 2) >= 0 && bccomp($c, '0', 2) >= 0 && (bccomp($d, '0', 2) > 0 xor bccomp($c, '0', 2) > 0), 'Each line must have one positive debit or credit.');
                if (! empty($line['tax_rule_id'])) {
                    $this->require(DB::table('accounting_tax_rules')->where('company_id', $companyId)->where('id', $line['tax_rule_id'])
                        ->whereNotNull('approved_by')->exists(), 'Tax rule does not belong to this company or is unapproved.');
                }
                $debit = bcadd($debit, $d, 2);
                $credit = bcadd($credit, $c, 2);
                $lines[] = ['account_id' => $account->id, 'debit' => $d, 'credit' => $c,
                    'tax_rule_id' => $line['tax_rule_id'] ?? null, 'invoice_item_id' => $line['invoice_item_id'] ?? null];
            }
            $this->require(count($lines) >= 2 && bccomp($debit, $credit, 2) === 0, 'Debit must equal credit.');
            $id = DB::table('accounting_entries')->insertGetId([
                'company_id' => $companyId, 'period_id' => $period->id, 'event_key' => $data['event_key'],
                'posting_date' => $data['posting_date'], 'description' => $data['description'],
                'invoice_id' => $data['invoice_id'] ?? null, 'reverses_entry_id' => $data['reverses_entry_id'] ?? null,
                'posted_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('accounting_entry_lines')->insert(array_map(fn ($line) => ['entry_id' => $id] + $line, $lines));
            $this->audit($companyId, $actor, 'entry', $id, 'posted', ['event_key' => $data['event_key']]);

            return DB::table('accounting_entries')->find($id);
        });
    }

    public function reverse(int $companyId, int $actor, int $entryId, string $date, string $reason): object
    {
        return DB::transaction(function () use ($companyId, $actor, $entryId, $date, $reason) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $entry = DB::table('accounting_entries')->where('company_id', $companyId)->where('id', $entryId)->first();
            abort_unless($entry, 404);
            $this->require(! $entry->reverses_entry_id, 'A reversal cannot itself be reversed.');
            // Invoice postings with VAT use an invoice corrective document, never a bare journal reversal.
            $this->require(! $entry->invoice_id, 'Correct invoice postings with a linked corrective invoice.');
            $this->require(str_starts_with($entry->event_key, 'manual:') || str_starts_with($entry->event_key, 'opening:'), 'Payment and advance events require a linked business correction.');
            $lines = DB::table('accounting_entry_lines')->where('entry_id', $entryId)->get()->map(fn ($l) => [
                'account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit,
                'tax_rule_id' => $l->tax_rule_id, 'invoice_item_id' => $l->invoice_item_id,
            ])->all();

            return $this->post($companyId, $actor, ['event_key' => 'reversal:'.$entryId, 'posting_date' => $date,
                'description' => $reason, 'reverses_entry_id' => $entryId, 'lines' => $lines]);
        });
    }

    public function audit(int $companyId, int $actor, string $type, int $id, string $action, array $details = []): void
    {
        DB::table('accounting_audit_events')->insert(['company_id' => $companyId, 'user_id' => $actor,
            'entity_type' => $type, 'entity_id' => $id, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['accounting' => $message]);
        }
    }
}
