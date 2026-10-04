<?php

namespace App\Services\Accounting;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * PANTHEON (Datalab) connector. The licence is the Pantheon SQL login of the company.
 * Reads chart of accounts / subjects / VAT codes and exports posted journal entries as
 * tHE_AcctTrans + tHE_AcctTransItem (Pantheon "temeljnica"), using the key format
 * yy + doc type + 7-digit sequence that Pantheon itself uses.
 */
class PantheonConnector
{
    public const CURRENCIES = ['BAM' => 'KM'];

    public function __construct(private AccountingLedger $ledger) {}

    public function settings(int $companyId): ?object
    {
        $row = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        if ($row) {
            unset($row->password);
            $row->has_password = true;
        }

        return $row;
    }

    public function save(int $companyId, int $actor, array $data): ?object
    {
        DB::transaction(function () use ($companyId, $actor, $data) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $exists = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->exists();
            $this->ledger->require($exists || filled($data['password'] ?? null), 'Pantheon password is required.');
            $values = collect($data)->only(['host', 'port', 'database', 'schema', 'username', 'allow_write', 'clerk_id', 'outgoing_doc_type', 'incoming_doc_type', 'journal_doc_type'])->all();
            if (filled($data['password'] ?? null)) {
                $values['password'] = Crypt::encryptString($data['password']);
            }
            DB::table('accounting_pantheon_connectors')->updateOrInsert(['company_id' => $companyId], $values + ['updated_by' => $actor, 'updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));
            $this->ledger->audit($companyId, $actor, 'pantheon', $companyId, 'connector_saved', collect($values)->except('password')->all());
        });

        return $this->settings($companyId);
    }

    public function test(int $companyId): array
    {
        try {
            $remote = $this->remote($companyId);
            $counts = ['accounts' => $remote->table($this->table($companyId, 'tHE_SetAccount'))->count(),
                'subjects' => $remote->table($this->table($companyId, 'tHE_SetSubj'))->count()];
            DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->update(['last_tested_at' => now(), 'last_test_status' => 'ok', 'last_test_error' => null]);

            return ['ok' => true] + $counts;
        } catch (\Throwable $e) {
            $message = $this->safeMessage($e);
            DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->update(['last_tested_at' => now(), 'last_test_status' => 'failed', 'last_test_error' => $message]);

            return ['ok' => false, 'error' => $message];
        }
    }

    public function preview(int $companyId, string $type): array
    {
        $remote = $this->remote($companyId);
        if ($type === 'accounts') {
            $local = DB::table('accounting_accounts')->where('company_id', $companyId)->pluck('code')->all();

            return $remote->table($this->table($companyId, 'tHE_SetAccount'))->orderBy('acAcct')->get(['acAcct', 'acName', 'acPermitPost', 'acSubject'])
                ->map(fn ($a) => ['code' => trim($a->acAcct), 'name' => trim((string) $a->acName), 'postable' => trim((string) $a->acPermitPost) !== 'N',
                    'requires_partner' => trim((string) $a->acSubject) === 'T', 'kind' => $this->kind(trim($a->acAcct)), 'exists' => in_array(trim($a->acAcct), $local, true)])->all();
        }
        if ($type === 'partners') {
            $local = DB::table('accounting_partners')->where('company_id', $companyId)->whereNotNull('tax_number')->pluck('tax_number')->all();

            return $remote->table($this->table($companyId, 'tHE_SetSubj'))->where('acActive', 'T')->orderBy('acSubject')
                ->get(['acSubject', 'acName2', 'acCode', 'acRegNo', 'acAddress', 'acPost', 'acCountry', 'acVATCodePrefix'])
                ->map(fn ($s) => ['key' => trim($s->acSubject), 'name' => trim((string) ($s->acName2 ?: $s->acSubject)), 'tax_number' => trim((string) $s->acCode) ?: null,
                    'registration_number' => trim((string) $s->acRegNo) ?: null, 'address' => trim(trim((string) $s->acAddress).' '.trim((string) $s->acPost)) ?: null,
                    'country' => trim((string) $s->acCountry), 'country_code' => preg_match('/^[A-Z]{2}$/', trim((string) $s->acVATCodePrefix)) ? trim($s->acVATCodePrefix) : null,
                    'exists' => trim((string) $s->acCode) !== '' && in_array(trim($s->acCode), $local, true)])->all();
        }
        $this->ledger->require($type === 'taxes', 'Unsupported Pantheon preview.');

        // VAT codes are reference data for mapping only; tax rules still require professional approval locally.
        return $remote->table($this->table($companyId, 'tHE_SetTax'))->where('acActive', 'T')->orderBy('acVATCode')->get(['acVATCode', 'acName', 'anVAT', 'acFiscalCode'])
            ->map(fn ($t) => ['code' => trim($t->acVATCode), 'name' => trim((string) $t->acName), 'rate' => (string) $t->anVAT, 'fiscal_code' => trim((string) $t->acFiscalCode)])->all();
    }

    /** Imports selected Pantheon accounts or subjects. Existing local records are never redefined. */
    public function import(int $companyId, int $actor, string $type, array $selected, string $reviewNote): array
    {
        $rows = collect($this->preview($companyId, $type))->keyBy($type === 'accounts' ? 'code' : 'key');

        return DB::transaction(function () use ($companyId, $actor, $type, $selected, $reviewNote, $rows) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $imported = 0;
            $skipped = [];
            foreach ($selected as $item) {
                $key = (string) ($item['code'] ?? $item['key'] ?? '');
                $row = $rows->get($key);
                if (! $row) {
                    $skipped[] = ['key' => $key, 'reason' => 'not_found'];

                    continue;
                }
                if ($type === 'accounts') {
                    $kind = $item['kind'] ?? $row['kind'];
                    if (! in_array($kind, ['asset', 'liability', 'equity', 'income', 'expense'], true) || DB::table('accounting_accounts')->where('company_id', $companyId)->where('code', $key)->exists()) {
                        $skipped[] = ['key' => $key, 'reason' => $kind ? 'exists' : 'kind_required'];

                        continue;
                    }
                    $local = DB::table('accounting_accounts')->insertGetId(['company_id' => $companyId, 'code' => $key, 'name' => $row['name'], 'kind' => $kind,
                        'active' => $row['postable'], 'approved_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $country = $item['country_code'] ?? $row['country_code'];
                    if (! $country || ($row['tax_number'] && DB::table('accounting_partners')->where('company_id', $companyId)->where('tax_number', $row['tax_number'])->exists())) {
                        $skipped[] = ['key' => $key, 'reason' => $country ? 'exists' : 'country_required'];

                        continue;
                    }
                    $local = DB::table('accounting_partners')->insertGetId(['company_id' => $companyId, 'name' => $row['name'], 'tax_number' => $row['tax_number'],
                        'address' => $row['address'], 'country_code' => $country, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->link($companyId, $actor, $type === 'accounts' ? 'account' : 'partner', $local, $key, 'import');
                $imported++;
            }
            $this->ledger->audit($companyId, $actor, 'pantheon', $companyId, 'pantheon_import', ['type' => $type, 'imported' => $imported, 'review_note' => $reviewNote]);

            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    /** Builds (dry run) or writes posted journal entries into Pantheon. */
    public function export(int $companyId, int $actor, string $from, string $to, bool $write): array
    {
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($connector !== null, 'Pantheon connector is not configured.');
        $remote = $this->remote($companyId);
        $accounts = $remote->table($this->table($companyId, 'tHE_SetAccount'))->get(['acAcct', 'acPermitPost', 'acSubject'])->keyBy(fn ($a) => trim($a->acAcct));
        $linked = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'entry')->pluck('local_id')->all();
        $entries = DB::table('accounting_entries')->where('company_id', $companyId)->whereBetween('posting_date', [$from, $to])->whereNotIn('id', $linked)->orderBy('posting_date')->orderBy('id')->get();
        $documents = [];
        foreach ($entries as $entry) {
            $documents[] = $this->document($companyId, $connector, $remote, $accounts, $entry);
        }
        $ready = array_values(array_filter($documents, fn ($d) => $d['problems'] === []));
        if (! $write) {
            return ['dry_run' => true, 'documents' => $documents, 'ready' => count($ready)];
        }
        $this->ledger->require((bool) $connector->allow_write, 'Writing to Pantheon is disabled for this connector.');
        $exported = [];
        foreach ($ready as $document) {
            $exported[] = $this->write($companyId, $actor, $connector, $remote, $document);
        }

        return ['dry_run' => false, 'exported' => $exported, 'skipped' => count($documents) - count($ready), 'documents' => $documents];
    }

    protected function remote(int $companyId): ConnectionInterface
    {
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($connector !== null, 'Pantheon connector is not configured.');
        $this->ledger->require(extension_loaded('pdo_sqlsrv'), 'The server PHP is missing the pdo_sqlsrv extension required for PANTHEON.');
        $name = 'pantheon_company_'.$companyId;
        config(["database.connections.$name" => ['driver' => 'sqlsrv', 'host' => $connector->host, 'port' => (string) $connector->port,
            'database' => $connector->database, 'username' => $connector->username, 'password' => Crypt::decryptString($connector->password),
            'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'trust_server_certificate' => true]]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function document(int $companyId, object $connector, ConnectionInterface $remote, $accounts, object $entry): array
    {
        $invoice = $entry->invoice_id ? DB::table('invoices')->where('company_id', $companyId)->where('id', $entry->invoice_id)->first() : null;
        $docType = ! $invoice ? $connector->journal_doc_type : ($invoice->direction === 'incoming' ? $connector->incoming_doc_type : $connector->outgoing_doc_type);
        $subject = $invoice?->partner_id ? $this->subject($companyId, $remote, (int) $invoice->partner_id) : '';
        $currency = $invoice ? (self::CURRENCIES[$invoice->currency] ?? $invoice->currency) : 'KM';
        $rate = $invoice && $currency !== 'KM' ? (string) ($invoice->exchange_rate ?: '1') : '1';
        $problems = [];
        $lines = [];
        foreach (DB::table('accounting_entry_lines')->join('accounting_accounts', 'accounting_accounts.id', '=', 'accounting_entry_lines.account_id')
            ->where('entry_id', $entry->id)->orderBy('accounting_entry_lines.id')->get(['accounting_accounts.code', 'debit', 'credit']) as $n => $line) {
            $remoteAccount = $accounts->get($line->code);
            if (! $remoteAccount || trim((string) $remoteAccount->acPermitPost) === 'N') {
                $problems[] = 'Account '.$line->code.' is missing or not postable in Pantheon.';
            } elseif (trim((string) $remoteAccount->acSubject) === 'T' && $subject === '') {
                $problems[] = 'Account '.$line->code.' requires a Pantheon subject (partner).';
            }
            $foreign = bccomp($rate, '1', 8) !== 0;
            $lines[] = ['anNo' => $n + 1, 'acAcct' => $line->code, 'acSubject' => $remoteAccount && trim((string) $remoteAccount->acSubject) === 'T' ? $subject : '',
                'anDebit' => (string) $line->debit, 'anCredit' => (string) $line->credit, 'acCurrency' => $foreign ? $currency : 'KM', 'anFXRate' => $foreign ? $rate : '1',
                'anValDebit' => $foreign ? Decimal::round(bcdiv((string) $line->debit, $rate, 10)) : '0', 'anValCredit' => $foreign ? Decimal::round(bcdiv((string) $line->credit, $rate, 10)) : '0'];
        }
        $doc = mb_substr((string) ($invoice ? ($invoice->supplier_number ?: $invoice->number) : $entry->description), 0, 50);

        return ['entry_id' => $entry->id, 'doc_type' => $docType, 'date' => (string) $entry->posting_date, 'document' => $doc,
            'document_date' => $invoice?->issued_at ?? $entry->posting_date, 'due_date' => $invoice?->due_at, 'vat_date' => $invoice?->tax_date ?? $entry->posting_date,
            'marker' => 'SF:'.$companyId.':'.$entry->id, 'description' => mb_substr((string) $entry->description, 0, 255),
            'debit' => Decimal::sum(array_column($lines, 'anDebit')), 'credit' => Decimal::sum(array_column($lines, 'anCredit')), 'lines' => $lines, 'problems' => $problems];
    }

    private function write(int $companyId, int $actor, object $connector, ConnectionInterface $remote, array $document): array
    {
        $header = $this->table($companyId, 'tHE_AcctTrans');
        $existing = $remote->table($header)->where('acNote', $document['marker'])->value('acKey');
        $key = $existing ? trim($existing) : $remote->transaction(function () use ($remote, $header, $connector, $document, $companyId) {
            $year = date('y', strtotime($document['date']));
            $prefix = $year.$document['doc_type'];
            $last = trim((string) $remote->table($header)->lockForUpdate()->where('acDocType', $document['doc_type'])->where('acKey', 'like', $prefix.'%')->orderByDesc('acKey')->value('acKey'));
            $sequence = $last !== '' && ctype_digit(substr($last, strlen($prefix))) ? (int) substr($last, strlen($prefix)) + 1 : 1;
            $key = $prefix.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT);
            $remote->table($header)->insert(['acKey' => $key, 'acDocType' => $document['doc_type'], 'adDate' => $document['date'], 'adDateOfEntry' => date('Y-m-d'),
                'anClerk' => (int) $connector->clerk_id, 'anDebit' => $document['debit'], 'anCredit' => $document['credit'], 'acNote' => $document['marker'],
                'acKeyView' => $year.'-'.$document['doc_type'].'-'.substr($key, -6), 'anUserIns' => (int) $connector->clerk_id, 'anUserChg' => (int) $connector->clerk_id]);
            foreach ($document['lines'] as $line) {
                $remote->table($this->table($companyId, 'tHE_AcctTransItem'))->insert($line + ['acKey' => $key, 'acDoc' => $document['document'],
                    'adDateDoc' => $document['document_date'], 'adDateDue' => $document['due_date'], 'adDateVAT' => $document['vat_date'],
                    'acNote' => $document['description'], 'anUserIns' => (int) $connector->clerk_id, 'anUserChg' => (int) $connector->clerk_id]);
            }

            return $key;
        });
        // The marker in acNote makes a retry relink instead of writing the same entry twice.
        $this->link($companyId, $actor, 'entry', $document['entry_id'], $key, 'export');

        return ['entry_id' => $document['entry_id'], 'pantheon_key' => $key, 'relinked' => (bool) $existing];
    }

    private function subject(int $companyId, ConnectionInterface $remote, int $partnerId): string
    {
        $link = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->where('local_id', $partnerId)->value('pantheon_key');
        if ($link) {
            return $link;
        }
        $tax = DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $partnerId)->value('tax_number');

        return $tax ? trim((string) $remote->table($this->table($companyId, 'tHE_SetSubj'))->where('acCode', $tax)->value('acSubject')) : '';
    }

    private function link(int $companyId, int $actor, string $type, int $localId, string $key, string $direction): void
    {
        DB::table('accounting_pantheon_links')->updateOrInsert(['company_id' => $companyId, 'entity_type' => $type, 'local_id' => $localId],
            ['pantheon_key' => $key, 'direction' => $direction, 'synced_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Suggested kind from the first digit of the BiH chart class; classes 7–9 need an explicit choice. */
    private function kind(string $code): ?string
    {
        return ['0' => 'asset', '1' => 'asset', '2' => 'asset', '3' => 'equity', '4' => 'liability', '5' => 'expense', '6' => 'income'][$code[0] ?? ''] ?? null;
    }

    private function table(int $companyId, string $table): string
    {
        $schema = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->value('schema') ?: 'dbo';

        return $schema.'.'.$table;
    }

    private function safeMessage(\Throwable $e): string
    {
        // Never echo DSNs or credentials back to the browser.
        return preg_replace('/(password|pwd)=[^;\s]*/i', '$1=***', mb_substr($e->getMessage(), 0, 500));
    }
}
