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
            $row->account_kinds = json_decode((string) ($row->account_kinds ?? ''), true) ?: (object) [];
            $row->last_sync_summary = json_decode((string) ($row->last_sync_summary ?? ''), true);
        }

        return $row;
    }

    public function save(int $companyId, int $actor, array $data): ?object
    {
        DB::transaction(function () use ($companyId, $actor, $data) {
            DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();
            $exists = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->exists();
            $this->ledger->require($exists || filled($data['password'] ?? null), 'Pantheon password is required.');
            $values = collect($data)->only(['host', 'port', 'database', 'schema', 'username', 'allow_write', 'clerk_id', 'outgoing_doc_type', 'incoming_doc_type', 'journal_doc_type',
                'sync_enabled', 'sync_interval_minutes', 'push_entries_from', 'default_country_code', 'sales_doc_types', 'delivery_doc_types'])->all();
            if (array_key_exists('account_kinds', $data)) {
                $values['account_kinds'] = json_encode((object) ($data['account_kinds'] ?? []));
            }
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
            $linked = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->pluck('pantheon_key')->all();

            // tHE_SetSubj also holds warehouses and departments; only business partners are synced.
            return $remote->table($this->table($companyId, 'tHE_SetSubj'))->where('acActive', 'T')
                ->where(fn ($q) => $q->whereNull('acWarehouse')->orWhere('acWarehouse', '<>', 'T'))
                ->where(fn ($q) => $q->whereNull('acDept')->orWhere('acDept', '<>', 'T'))->orderBy('acSubject')
                ->get(['acSubject', 'acName2', 'acCode', 'acRegNo', 'acAddress', 'acPost', 'acCountry', 'acVATCodePrefix'])
                ->map(fn ($s) => ['key' => trim($s->acSubject), 'name' => trim((string) ($s->acName2 ?: $s->acSubject)), 'tax_number' => trim((string) $s->acCode) ?: null,
                    'registration_number' => trim((string) $s->acRegNo) ?: null, 'address' => trim(trim((string) $s->acAddress).' '.trim((string) $s->acPost)) ?: null,
                    'country' => trim((string) $s->acCountry), 'country_code' => preg_match('/^[A-Z]{2}$/', trim((string) $s->acVATCodePrefix)) ? trim($s->acVATCodePrefix) : null,
                    'exists' => in_array(trim($s->acSubject), $linked, true) || (trim((string) $s->acCode) !== '' && in_array(trim($s->acCode), $local, true))])->all();
        }
        $this->ledger->require($type === 'taxes', 'Unsupported Pantheon preview.');

        // VAT codes are reference data for mapping only; tax rules still require professional approval locally.
        return $remote->table($this->table($companyId, 'tHE_SetTax'))->where('acActive', 'T')->orderBy('acVATCode')->get(['acVATCode', 'acName', 'anVAT', 'acFiscalCode'])
            ->map(fn ($t) => ['code' => trim($t->acVATCode), 'name' => trim((string) $t->acName), 'rate' => (string) $t->anVAT, 'fiscal_code' => trim((string) $t->acFiscalCode)])->all();
    }

    /**
     * Continuous two-way sync. PANTHEON is the master for accounts and partners: new rows are created,
     * linked rows follow PANTHEON changes, matching local rows are adopted instead of duplicated, and
     * accounts removed in PANTHEON are deactivated. Posted entries are pushed when writing is enabled.
     * An account kind, once set, is never changed by sync because postings depend on it.
     */
    public function sync(int $companyId, int $actor): array
    {
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($connector !== null, 'Pantheon connector is not configured.');
        try {
            $accounts = $this->preview($companyId, 'accounts');
            $partners = $this->preview($companyId, 'partners');
            $summary = DB::transaction(function () use ($companyId, $actor, $connector, $accounts, $partners) {
                DB::table('accounting_settings')->where('company_id', $companyId)->lockForUpdate()->first();

                return ['accounts' => $this->syncAccounts($companyId, $actor, $connector, $accounts),
                    'partners' => $this->syncPartners($companyId, $actor, $connector, $partners)];
            });
            $summary['entries'] = $this->pushEntries($companyId, $actor, $connector);
            $this->ledger->audit($companyId, $actor, 'pantheon', $companyId, 'pantheon_sync', $summary);
            $this->syncState($companyId, 'ok', null, $summary);

            return ['ok' => true] + $summary;
        } catch (\Throwable $e) {
            $this->syncState($companyId, 'failed', $this->safeMessage($e), null);
            throw $e;
        }
    }

    private function syncAccounts(int $companyId, int $actor, object $connector, array $remote): array
    {
        $kinds = json_decode((string) ($connector->account_kinds ?? ''), true) ?: [];
        $links = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'account')->pluck('local_id', 'pantheon_key');
        $local = DB::table('accounting_accounts')->where('company_id', $companyId)->get()->keyBy('code');
        $result = ['created' => 0, 'updated' => 0, 'linked' => 0, 'deactivated' => 0, 'pending' => []];
        $seen = [];
        foreach ($remote as $row) {
            $code = $row['code'];
            $seen[$code] = true;
            $existing = $links->has($code) ? $local->firstWhere('id', $links[$code]) : $local->get($code);
            if ($existing) {
                if (! $links->has($code)) {
                    $this->link($companyId, $actor, 'account', (int) $existing->id, $code, 'sync');
                    $result['linked']++;
                }
                if ($existing->name !== $row['name'] || (bool) $existing->active !== $row['postable']) {
                    DB::table('accounting_accounts')->where('id', $existing->id)->update(['name' => $row['name'], 'active' => $row['postable'], 'updated_at' => now()]);
                    $result['updated']++;
                }

                continue;
            }
            $kind = $kinds[$code] ?? $row['kind'];
            if (! in_array($kind, ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
                $result['pending'][] = ['key' => $code, 'name' => $row['name'], 'reason' => 'kind_required'];

                continue;
            }
            $id = DB::table('accounting_accounts')->insertGetId(['company_id' => $companyId, 'code' => $code, 'name' => $row['name'], 'kind' => $kind,
                'active' => $row['postable'], 'approved_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->link($companyId, $actor, 'account', $id, $code, 'sync');
            $result['created']++;
        }
        foreach ($links as $code => $id) {
            if (! isset($seen[$code]) && DB::table('accounting_accounts')->where('id', $id)->where('active', true)->update(['active' => false, 'updated_at' => now()])) {
                $result['deactivated']++;
            }
        }

        return $result;
    }

    private function syncPartners(int $companyId, int $actor, object $connector, array $remote): array
    {
        $links = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->pluck('local_id', 'pantheon_key');
        $result = ['created' => 0, 'updated' => 0, 'linked' => 0, 'pending' => []];
        foreach ($remote as $row) {
            $key = $row['key'];
            $country = $row['country_code'] ?? $connector->default_country_code;
            $partners = DB::table('accounting_partners')->where('company_id', $companyId);
            $existing = $links->has($key) ? (clone $partners)->where('id', $links[$key])->first()
                : ($row['tax_number'] ? (clone $partners)->where('tax_number', $row['tax_number'])->first() : null);
            if ($existing) {
                if (! $links->has($key)) {
                    $this->link($companyId, $actor, 'partner', (int) $existing->id, $key, 'sync');
                    $result['linked']++;
                }
                $changes = array_filter(['name' => $row['name'], 'address' => $row['address'], 'country_code' => $row['country_code']], fn ($v) => $v !== null);
                // A tax number is only filled in, never moved onto a number another local partner already uses.
                if (! $existing->tax_number && $row['tax_number'] && ! (clone $partners)->where('tax_number', $row['tax_number'])->exists()) {
                    $changes['tax_number'] = $row['tax_number'];
                }
                $changes = array_filter($changes, fn ($v, $k) => (string) $existing->{$k} !== (string) $v, ARRAY_FILTER_USE_BOTH);
                if ($changes) {
                    DB::table('accounting_partners')->where('id', $existing->id)->update($changes + ['updated_at' => now()]);
                    $result['updated']++;
                }

                continue;
            }
            if (! $country) {
                $result['pending'][] = ['key' => $key, 'name' => $row['name'], 'reason' => 'country_required'];

                continue;
            }
            $id = DB::table('accounting_partners')->insertGetId(['company_id' => $companyId, 'name' => $row['name'], 'tax_number' => $row['tax_number'],
                'address' => $row['address'], 'country_code' => $country, 'created_at' => now(), 'updated_at' => now()]);
            $this->link($companyId, $actor, 'partner', $id, $key, 'sync');
            $result['created']++;
        }

        return $result;
    }

    private function pushEntries(int $companyId, int $actor, object $connector): array
    {
        if (! $connector->allow_write || ! $connector->push_entries_from) {
            return ['enabled' => false, 'exported' => 0, 'waiting' => 0];
        }
        $result = $this->export($companyId, $actor, (string) $connector->push_entries_from, now()->toDateString(), true);

        return ['enabled' => true, 'exported' => count($result['exported']), 'waiting' => $result['skipped'],
            'problems' => array_values(array_filter(array_map(fn ($d) => $d['problems'] ? ['entry_id' => $d['entry_id'], 'problems' => $d['problems']] : null, $result['documents'])))];
    }

    /** Connectors whose interval has elapsed; used by the scheduled sync command. */
    public function due(bool $ignoreInterval = false): array
    {
        return DB::table('accounting_pantheon_connectors')->where('sync_enabled', true)->get(['company_id', 'sync_interval_minutes', 'last_synced_at', 'updated_by'])
            ->filter(fn ($c) => $ignoreInterval || ! $c->last_synced_at || now()->diffInMinutes($c->last_synced_at, true) >= max(1, (int) $c->sync_interval_minutes))
            ->values()->all();
    }

    private function syncState(int $companyId, string $status, ?string $error, ?array $summary): void
    {
        DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->update(['last_synced_at' => now(), 'last_sync_status' => $status, 'last_sync_error' => $error]
            + ($summary === null ? [] : ['last_sync_summary' => json_encode($summary)]));
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

    /** Read access for other PANTHEON integrations (CRM) that share this connector licence. */
    public function connection(int $companyId): ConnectionInterface
    {
        return $this->remote($companyId);
    }

    public function remoteTable(int $companyId, string $table): string
    {
        return $this->table($companyId, $table);
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
