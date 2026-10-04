<?php

namespace App\Services\Crm;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * SmartFreight side of the CRM: leads, follow-ups, ownership, lost offers and the sales report.
 * PANTHEON documents stay read-only here except for these SmartFreight-only fields.
 */
class CrmPipeline
{
    public const MANUAL_STAGES = ['lead', 'offer', 'order', 'closed', 'lost'];

    public function __construct(private AccountingLedger $ledger) {}

    public function documents(int $companyId, array $filters): array
    {
        $query = DB::table('crm_documents')->where('company_id', $companyId);
        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }
        if (! empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }
        if (! empty($filters['customer_key'])) {
            $query->where('customer_key', $filters['customer_key']);
        }
        if (! empty($filters['from'])) {
            $query->where('issued_on', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('issued_on', '<=', $filters['to']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('title', 'like', $term));
        }

        return $query->orderByDesc('issued_on')->orderByDesc('id')->limit(min((int) ($filters['limit'] ?? 200), 500))->get()
            ->map(fn ($d) => $this->decode($d))->all();
    }

    public function show(int $companyId, int $id): array
    {
        $document = $this->decode(DB::table('crm_documents')->where('company_id', $companyId)->where('id', $id)->first() ?? abort(404));
        $contacts = DB::table('crm_contacts')->where('company_id', $companyId)->where(function ($q) use ($document) {
            $q->when($document->customer_key, fn ($q) => $q->where('customer_key', $document->customer_key))
                ->when($document->partner_id, fn ($q) => $q->orWhere('partner_id', $document->partner_id));
        })->when(! $document->customer_key && ! $document->partner_id, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('active')->orderBy('name')->get();

        return ['document' => $document, 'items' => DB::table('crm_document_items')->where('crm_document_id', $id)->orderBy('line_no')->get(),
            'contacts' => $contacts, 'follow_ups' => DB::table('crm_follow_ups')->where('company_id', $companyId)->where('crm_document_id', $id)->orderBy('due_on')->get()];
    }

    /** A SmartFreight lead or offer that does not exist in PANTHEON (yet). */
    public function create(int $companyId, int $actor, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $data) {
            $partner = ! empty($data['partner_id']) ? DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $data['partner_id'])->first() : null;
            $this->ledger->require(empty($data['partner_id']) || $partner !== null, 'Customer does not belong to this company.');
            $this->ledger->require($partner !== null || filled($data['customer_name'] ?? null), 'Choose a customer or enter a name.');
            $total = Decimal::value((string) ($data['total_amount'] ?? '0'));
            $id = DB::table('crm_documents')->insertGetId(['company_id' => $companyId, 'partner_id' => $partner?->id, 'customer_id' => $partner?->customer_id,
                'source' => 'smartfreight', 'stage' => $data['stage'] ?? 'lead', 'customer_name' => $partner?->name ?? $data['customer_name'],
                'customer_tax_number' => $partner?->tax_number, 'contact_name' => $data['contact_name'] ?? null, 'title' => $data['title'] ?? null,
                'issued_on' => $data['issued_on'] ?? now()->toDateString(), 'valid_until' => $data['valid_until'] ?? null, 'currency' => $data['currency'] ?? 'BAM',
                'net_amount' => $total, 'total_amount' => $total, 'note' => $data['note'] ?? null, 'owner_user_id' => $data['owner_user_id'] ?? $actor,
                'next_follow_up_on' => $data['next_follow_up_on'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger->audit($companyId, $actor, 'crm_document', $id, 'crm_created', ['stage' => $data['stage'] ?? 'lead']);

            return DB::table('crm_documents')->find($id);
        });
    }

    public function update(int $companyId, int $actor, int $id, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $id, $data) {
            $document = DB::table('crm_documents')->where('company_id', $companyId)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $changes = collect($data)->only(['owner_user_id', 'next_follow_up_on', 'lost_reason'])->all();
            if (array_key_exists('stage', $data) && $data['stage'] !== $document->stage) {
                if ($document->source === 'pantheon') {
                    // PANTHEON owns the progress of its documents; SmartFreight may only mark an open offer lost or reopen it.
                    $this->ledger->require(($document->stage === 'offer' && $data['stage'] === 'lost') || ($document->stage === 'lost' && $data['stage'] === 'offer'),
                        'PANTHEON documents change stage in PANTHEON. Only an open offer can be marked lost here.');
                } else {
                    $this->ledger->require(in_array($data['stage'], self::MANUAL_STAGES, true), 'Unsupported stage.');
                }
                $changes['stage'] = $data['stage'];
            }
            if (($changes['stage'] ?? $document->stage) === 'lost') {
                $this->ledger->require(filled($changes['lost_reason'] ?? $document->lost_reason), 'Enter why the offer was lost.');
            }
            if ($document->source === 'smartfreight') {
                $changes += collect($data)->only(['title', 'contact_name', 'note', 'valid_until'])->all();
                if (array_key_exists('total_amount', $data)) {
                    $changes['total_amount'] = $changes['net_amount'] = Decimal::value((string) $data['total_amount']);
                }
            }
            if ($changes) {
                DB::table('crm_documents')->where('id', $id)->update($changes + ['updated_at' => now()]);
                $this->ledger->audit($companyId, $actor, 'crm_document', $id, 'crm_updated', $changes);
            }

            return DB::table('crm_documents')->find($id);
        });
    }

    public function followUp(int $companyId, int $actor, array $data): object
    {
        if (! empty($data['crm_document_id'])) {
            $this->ledger->require(DB::table('crm_documents')->where('company_id', $companyId)->where('id', $data['crm_document_id'])->exists(), 'Document does not belong to this company.');
        }
        if (! empty($data['partner_id'])) {
            $this->ledger->require(DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $data['partner_id'])->exists(), 'Customer does not belong to this company.');
        }
        $id = DB::table('crm_follow_ups')->insertGetId(['company_id' => $companyId, 'crm_document_id' => $data['crm_document_id'] ?? null, 'partner_id' => $data['partner_id'] ?? null,
            'due_on' => $data['due_on'], 'note' => $data['note'], 'owner_user_id' => $data['owner_user_id'] ?? $actor, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        if (! empty($data['crm_document_id'])) {
            $this->refreshNextFollowUp((int) $data['crm_document_id']);
        }

        return DB::table('crm_follow_ups')->find($id);
    }

    public function completeFollowUp(int $companyId, int $id): object
    {
        $followUp = DB::table('crm_follow_ups')->where('company_id', $companyId)->where('id', $id)->first() ?? abort(404);
        DB::table('crm_follow_ups')->where('id', $id)->whereNull('done_at')->update(['done_at' => now(), 'updated_at' => now()]);
        if ($followUp->crm_document_id) {
            $this->refreshNextFollowUp((int) $followUp->crm_document_id);
        }

        return DB::table('crm_follow_ups')->find($id);
    }

    public function dueFollowUps(int $companyId): array
    {
        return DB::table('crm_follow_ups')->leftJoin('crm_documents', 'crm_documents.id', '=', 'crm_follow_ups.crm_document_id')
            ->where('crm_follow_ups.company_id', $companyId)->whereNull('crm_follow_ups.done_at')->orderBy('crm_follow_ups.due_on')->limit(200)
            ->get(['crm_follow_ups.*', 'crm_documents.number', 'crm_documents.customer_name', 'crm_documents.stage'])->all();
    }

    /**
     * Sales report per customer and currency: offers issued in the period, how many converted
     * (confirmed, delivered or invoiced), how many are still open and how many were lost/closed.
     * Amounts are never summed across currencies.
     */
    public function report(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('crm_documents')->where('company_id', $companyId)->whereBetween('issued_on', [$from, $to])
            ->get(['partner_id', 'customer_key', 'customer_name', 'currency', 'stage', 'total_amount', 'issued_on']);
        $customers = [];
        $stages = [];
        $months = [];
        foreach ($rows as $row) {
            $group = $this->group($row->stage);
            $key = ($row->partner_id ? 'p'.$row->partner_id : 'k'.($row->customer_key ?: $row->customer_name)).'|'.$row->currency;
            $customers[$key] ??= ['partner_id' => $row->partner_id, 'customer_key' => $row->customer_key, 'customer_name' => $row->customer_name, 'currency' => $row->currency,
                'documents' => 0, 'converted' => 0, 'open' => 0, 'lost' => 0, 'total_value' => '0.00', 'converted_value' => '0.00', 'open_value' => '0.00', 'last_issued_on' => null];
            $c = &$customers[$key];
            $c['documents']++;
            $c[$group]++;
            $c['total_value'] = bcadd($c['total_value'], (string) $row->total_amount, 2);
            if ($group !== 'lost') {
                $c[$group.'_value'] = bcadd($c[$group.'_value'], (string) $row->total_amount, 2);
            }
            $c['last_issued_on'] = max((string) $c['last_issued_on'], (string) $row->issued_on);
            unset($c);
            $stageKey = $row->stage.'|'.$row->currency;
            $stages[$stageKey] ??= ['stage' => $row->stage, 'currency' => $row->currency, 'documents' => 0, 'value' => '0.00'];
            $stages[$stageKey]['documents']++;
            $stages[$stageKey]['value'] = bcadd($stages[$stageKey]['value'], (string) $row->total_amount, 2);
            $monthKey = substr((string) $row->issued_on, 0, 7).'|'.$row->currency;
            $months[$monthKey] ??= ['month' => substr((string) $row->issued_on, 0, 7), 'currency' => $row->currency, 'documents' => 0, 'converted' => 0, 'value' => '0.00'];
            $months[$monthKey]['documents']++;
            $months[$monthKey]['converted'] += $group === 'converted' ? 1 : 0;
            $months[$monthKey]['value'] = bcadd($months[$monthKey]['value'], (string) $row->total_amount, 2);
        }
        $customers = array_map(fn ($c) => $c + ['conversion_rate' => $c['converted'] + $c['lost'] > 0 ? round(100 * $c['converted'] / ($c['converted'] + $c['lost']), 1) : null,
            'converted_share' => round(100 * $c['converted'] / $c['documents'], 1)], array_values($customers));
        usort($customers, fn ($a, $b) => bccomp($b['total_value'], $a['total_value'], 2));
        $months = array_values($months);
        usort($months, fn ($a, $b) => strcmp($a['month'], $b['month']));
        $total = count($rows);
        $converted = $rows->filter(fn ($r) => $this->group($r->stage) === 'converted')->count();
        $decided = $converted + $rows->filter(fn ($r) => $this->group($r->stage) === 'lost')->count();

        return ['from' => $from, 'to' => $to, 'documents' => $total, 'converted' => $converted, 'open' => $rows->filter(fn ($r) => $this->group($r->stage) === 'open')->count(),
            'conversion_rate' => $decided > 0 ? round(100 * $converted / $decided, 1) : null, 'converted_share' => $total > 0 ? round(100 * $converted / $total, 1) : null, 'customers' => $customers, 'stages' => array_values($stages), 'months' => $months];
    }

    private function group(string $stage): string
    {
        return in_array($stage, PantheonCrmSync::CONVERTED, true) ? 'converted' : (in_array($stage, ['lead', 'offer'], true) ? 'open' : 'lost');
    }

    private function refreshNextFollowUp(int $documentId): void
    {
        DB::table('crm_documents')->where('id', $documentId)->update(['next_follow_up_on' => DB::table('crm_follow_ups')->where('crm_document_id', $documentId)->whereNull('done_at')->min('due_on')]);
    }

    private function decode(object $document): object
    {
        $document->linked_documents = json_decode((string) ($document->linked_documents ?? ''), true) ?: [];

        return $document;
    }
}
