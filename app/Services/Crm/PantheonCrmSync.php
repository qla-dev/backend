<?php

namespace App\Services\Crm;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\Decimal;
use App\Services\Accounting\PantheonConnector;
use Illuminate\Support\Facades\DB;

/**
 * Read-only CRM pull from PANTHEON. Offers and orders are tHE_Order rows of the configured sales
 * document types; conversion comes from tHE_LinkMoveItemOrderItem (order item -> delivery/invoice item),
 * because Trendy does not maintain the offer status (most "Ponuda" rows are already delivered).
 * Nothing is ever written to PANTHEON. SmartFreight-only fields (owner, follow-up, lost) survive every pull.
 */
class PantheonCrmSync
{
    public const STAGES = ['lead', 'offer', 'order', 'in_delivery', 'delivered', 'invoiced', 'closed', 'lost'];

    public const CONVERTED = ['order', 'in_delivery', 'delivered', 'invoiced'];

    public function __construct(private PantheonConnector $pantheon, private AccountingLedger $ledger) {}

    public function pull(int $companyId, int $actor, bool $full = false): array
    {
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($connector !== null, 'Pantheon connector is not configured.');
        $types = array_values(array_filter(array_map('trim', explode(',', (string) $connector->sales_doc_types))));
        $deliveryTypes = array_values(array_filter(array_map('trim', explode(',', (string) $connector->delivery_doc_types))));
        $this->ledger->require($types !== [], 'No PANTHEON sales document types are configured.');
        $remote = $this->pantheon->connection($companyId);
        $orders = $this->pantheon->remoteTable($companyId, 'tHE_Order');
        $query = $remote->table($orders)->whereIn('acDocType', $types);
        if (! $full && $connector->last_crm_sync_at) {
            // Recent documents are refreshed because deliveries change them without touching the header.
            $query->where(fn ($q) => $q->where('adTimeChg', '>=', $connector->last_crm_sync_at)->orWhere('adDate', '>=', now()->subMonths(12)->toDateString()));
        }
        $summary = ['documents' => 0, 'created' => 0, 'items' => 0, 'contacts' => 0, 'unmatched_customers' => 0];
        $customers = [];
        $query->orderBy('acKey')->select(['acKey', 'acDocType', 'adDate', 'acStatus', 'acReceiver', 'acContactPrsn', 'adDateValid', 'adDeliveryDeadline',
            'acCurrency', 'anVAT', 'anForPay', 'acNote', 'acDoc1', 'acKeyView', 'acFinished'])
            ->chunk(400, function ($headers) use ($companyId, $actor, $remote, $deliveryTypes, &$summary, &$customers) {
                $this->storeChunk($companyId, $actor, $remote, $headers, $deliveryTypes, $summary, $customers);
            });
        $summary['contacts'] = $this->pullContacts($companyId, $remote, array_keys($customers));
        DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->update(['last_crm_sync_at' => now()]);
        $this->ledger->audit($companyId, $actor, 'crm', $companyId, 'pantheon_crm_pull', $summary);

        return $summary;
    }

    private function storeChunk(int $companyId, int $actor, $remote, $headers, array $deliveryTypes, array &$summary, array &$customers): void
    {
        $keys = $headers->map(fn ($h) => trim($h->acKey))->all();
        $items = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_OrderItem'))->whereIn('acKey', $keys)
            ->get(['acKey', 'anNo', 'acIdent', 'acName', 'anQty', 'acUM', 'anPrice', 'anRebate', 'anVAT', 'adDeliveryDeadline'])->groupBy(fn ($i) => trim($i->acKey));
        $links = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_LinkMoveItemOrderItem'))->whereIn('acLnkKey', $keys)->get(['acKey', 'acLnkKey', 'anLnkNo', 'anQty']);
        // SQL Server allows 2100 parameters per statement, so linked documents are read in batches.
        $moves = $links->pluck('acKey')->map(fn ($k) => trim($k))->unique()->chunk(1000)
            ->flatMap(fn ($batch) => $remote->table($this->pantheon->remoteTable($companyId, 'tHE_Move'))->whereIn('acKey', $batch->values()->all())
                ->get(['acKey', 'acDocType', 'adDate', 'acKeyView']))->keyBy(fn ($m) => trim($m->acKey));
        $links = $links->groupBy(fn ($l) => trim($l->acLnkKey));
        $partners = $this->partners($companyId, $remote, $headers->pluck('acReceiver')->map(fn ($r) => trim((string) $r))->filter()->unique()->values()->all());
        DB::transaction(function () use ($companyId, $actor, $headers, $items, $links, $moves, $partners, $deliveryTypes, &$summary, &$customers) {
            foreach ($headers as $h) {
                $key = trim($h->acKey);
                $receiver = trim((string) $h->acReceiver);
                $customers[$receiver] = true;
                $lines = $items->get($key, collect());
                $orderLinks = $links->get($key, collect());
                $delivered = [];
                foreach ($orderLinks as $l) {
                    $delivered[(int) $l->anLnkNo] = bcadd($delivered[(int) $l->anLnkNo] ?? '0', $this->dec($l->anQty, 4), 4);
                }
                $linked = $orderLinks->map(fn ($l) => $moves->get(trim($l->acKey)))->filter()->unique(fn ($m) => trim($m->acKey))
                    ->map(fn ($m) => ['key' => trim($m->acKey), 'number' => trim((string) $m->acKeyView) ?: trim($m->acKey), 'doc_type' => trim($m->acDocType),
                        'date' => substr((string) $m->adDate, 0, 10), 'kind' => in_array(trim($m->acDocType), $deliveryTypes, true) ? 'delivery' : 'invoice'])->values()->all();
                $ordered = '0';
                $shipped = '0';
                foreach ($lines as $line) {
                    $quantity = $this->dec($line->anQty, 4);
                    $done = $delivered[(int) $line->anNo] ?? '0';
                    $ordered = bcadd($ordered, $quantity, 4);
                    // Over-delivery on one line must not hide an undelivered line.
                    $shipped = bcadd($shipped, bccomp($done, $quantity, 4) > 0 ? $quantity : $done, 4);
                }
                $partner = $partners[$receiver] ?? ['partner_id' => null, 'customer_id' => null, 'name' => $receiver, 'tax_number' => null];
                $summary['unmatched_customers'] += $partner['partner_id'] || $partner['customer_id'] ? 0 : 1;
                $stage = $this->stage((string) trim((string) $h->acStatus), $linked, $ordered, $shipped);
                $existing = DB::table('crm_documents')->where('company_id', $companyId)->where('pantheon_key', $key)->first();
                if ($existing && $existing->stage === 'lost' && $stage === 'offer') {
                    $stage = 'lost';
                }
                // Sent to tracking: SmartFreight's shipment drives the stage; PANTHEON may only move it forward.
                $rank = array_flip(['lead', 'offer', 'order', 'in_delivery', 'delivered', 'invoiced']);
                if ($existing && ($existing->load_id ?? null) && ($rank[$existing->stage] ?? -1) > ($rank[$stage] ?? -1)) {
                    $stage = $existing->stage;
                }
                $total = $this->dec($h->anForPay, 2);
                $vat = $this->dec($h->anVAT, 2);
                $values = ['partner_id' => $partner['partner_id'], 'customer_id' => $partner['customer_id'], 'source' => 'pantheon', 'number' => trim((string) $h->acKeyView) ?: $key,
                    'doc_type' => trim($h->acDocType), 'pantheon_status' => trim((string) $h->acStatus) ?: null, 'stage' => $stage, 'customer_name' => mb_substr($partner['name'] ?: $receiver, 0, 255),
                    'customer_key' => $receiver, 'customer_tax_number' => $partner['tax_number'], 'contact_name' => mb_substr(trim((string) $h->acContactPrsn), 0, 255) ?: null,
                    'title' => mb_substr(trim((string) $h->acDoc1), 0, 255) ?: null, 'issued_on' => substr((string) $h->adDate, 0, 10) ?: null,
                    'valid_until' => $h->adDateValid ? substr((string) $h->adDateValid, 0, 10) : null, 'delivery_deadline' => $h->adDeliveryDeadline ? substr((string) $h->adDeliveryDeadline, 0, 10) : null,
                    'currency' => $this->currency((string) $h->acCurrency), 'net_amount' => bcsub($total, $vat, 2), 'vat_amount' => $vat, 'total_amount' => $total,
                    'ordered_quantity' => $ordered, 'delivered_quantity' => $shipped, 'linked_documents' => json_encode($linked), 'note' => mb_substr(trim((string) $h->acNote), 0, 5000) ?: null,
                    'synced_at' => now(), 'updated_at' => now()];
                if ($existing && $existing->source === 'smartfreight') {
                    // Pushed from SmartFreight (CrmPantheonPush): our content is the master. Read back only
                    // what PANTHEON adds: delivery/invoice progress, moving the stage forward, never back.
                    DB::table('crm_documents')->where('id', $existing->id)->update(collect($values)->only(['pantheon_status', 'ordered_quantity', 'delivered_quantity', 'linked_documents', 'synced_at', 'updated_at'])->all()
                        + (($rank[$stage] ?? -1) > ($rank[$existing->stage] ?? -1) && in_array($stage, ['in_delivery', 'delivered', 'invoiced'], true) ? ['stage' => $stage] : []));
                    foreach ($lines as $line) {
                        DB::table('crm_document_items')->where('crm_document_id', $existing->id)->where('line_no', (int) $line->anNo)->update(['delivered_quantity' => $delivered[(int) $line->anNo] ?? '0']);
                    }
                    $summary['documents']++;

                    continue;
                }
                if ($existing) {
                    DB::table('crm_documents')->where('id', $existing->id)->update($values);
                    $id = $existing->id;
                } else {
                    $id = DB::table('crm_documents')->insertGetId($values + ['company_id' => $companyId, 'pantheon_key' => $key, 'created_by' => $actor, 'created_at' => now()]);
                    $summary['created']++;
                }
                $seen = [];
                foreach ($lines as $line) {
                    $no = (int) $line->anNo;
                    $seen[] = $no;
                    DB::table('crm_document_items')->updateOrInsert(['crm_document_id' => $id, 'line_no' => $no], ['item_code' => trim((string) $line->acIdent) ?: null,
                        'name' => mb_substr(trim((string) $line->acName), 0, 255) ?: '—', 'quantity' => $this->dec($line->anQty, 4), 'delivered_quantity' => $delivered[$no] ?? '0',
                        'unit' => trim((string) $line->acUM) ?: null, 'unit_price' => $this->dec($line->anPrice, 4), 'discount_percent' => $this->dec($line->anRebate, 4),
                        'vat_percent' => $line->anVAT === null ? null : $this->dec($line->anVAT, 4), 'delivery_deadline' => $line->adDeliveryDeadline ? substr((string) $line->adDeliveryDeadline, 0, 10) : null]);
                    $summary['items']++;
                }
                // Lines removed in PANTHEON leave the local cache of this one document.
                DB::table('crm_document_items')->where('crm_document_id', $id)->whereNotIn('line_no', $seen ?: [0])->delete();
                $summary['documents']++;
            }
        });
    }

    /** Matches PANTHEON receivers to existing SmartFreight clients: PANTHEON link, then tax ID, then platform customer. */
    private function partners(int $companyId, $remote, array $receivers): array
    {
        if ($receivers === []) {
            return [];
        }
        $subjects = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetSubj'))->whereIn('acSubject', $receivers)->get(['acSubject', 'acName2', 'acCode'])
            ->keyBy(fn ($s) => trim($s->acSubject));
        $links = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->whereIn('pantheon_key', $receivers)->pluck('local_id', 'pantheon_key');
        $result = [];
        foreach ($receivers as $receiver) {
            $subject = $subjects->get($receiver);
            $tax = $subject ? (trim((string) $subject->acCode) ?: null) : null;
            $partner = $links->has($receiver) ? DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $links[$receiver])->first()
                : ($tax ? DB::table('accounting_partners')->where('company_id', $companyId)->where('tax_number', $tax)->first() : null);
            $customer = $partner->customer_id ?? ($tax ? DB::table('customers')->where('tax_number', $tax)->value('id') : null);
            $result[$receiver] = ['partner_id' => $partner->id ?? null, 'customer_id' => $customer, 'tax_number' => $tax,
                'name' => $partner->name ?? ($subject ? trim((string) ($subject->acName2 ?: $subject->acSubject)) : $receiver)];
        }

        return $result;
    }

    private function pullContacts(int $companyId, $remote, array $receivers): int
    {
        $count = 0;
        foreach (array_chunk(array_values(array_filter($receivers)), 400) as $chunk) {
            $contacts = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetSubjContact'))->whereIn('acSubject', $chunk)
                ->get(['acSubject', 'anNo', 'acActive', 'acName', 'acSurname', 'acFunction']);
            $addresses = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetSubjContactAddress'))->whereIn('acSubject', $chunk)
                ->get(['acSubject', 'anNo', 'acType', 'acPhone'])->groupBy(fn ($a) => trim($a->acSubject).'#'.(int) $a->anNo);
            $partners = DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->whereIn('pantheon_key', $chunk)->pluck('local_id', 'pantheon_key');
            foreach ($contacts as $c) {
                $subject = trim($c->acSubject);
                $channels = $addresses->get($subject.'#'.(int) $c->anNo, collect());
                // acType E holds an e-mail address in acPhone; other types are phone numbers.
                $email = $channels->first(fn ($a) => trim((string) $a->acType) === 'E');
                $phone = $channels->first(fn ($a) => trim((string) $a->acType) !== 'E');
                DB::table('crm_contacts')->updateOrInsert(['company_id' => $companyId, 'customer_key' => $subject, 'pantheon_no' => (int) $c->anNo],
                    ['partner_id' => $partners[$subject] ?? null, 'name' => mb_substr(trim(trim((string) $c->acName).' '.trim((string) $c->acSurname)), 0, 255) ?: '—',
                        'function' => mb_substr(trim((string) $c->acFunction), 0, 255) ?: null, 'email' => $email ? mb_substr(trim((string) $email->acPhone), 0, 255) : null,
                        'phone' => $phone ? mb_substr(trim((string) $phone->acPhone), 0, 60) : null, 'active' => trim((string) $c->acActive) !== 'F', 'source' => 'pantheon',
                        'created_at' => now(), 'updated_at' => now()]);
                $count++;
            }
        }

        return $count;
    }

    private function stage(string $status, array $linked, string $ordered, string $shipped): string
    {
        if (collect($linked)->contains('kind', 'invoice')) {
            return 'invoiced';
        }
        if ($linked !== []) {
            return bccomp($ordered, '0', 4) > 0 && bccomp($shipped, $ordered, 4) >= 0 ? 'delivered' : 'in_delivery';
        }

        return match ($status) {
            'Z' => 'closed', '2' => 'order', default => 'offer'
        };
    }

    private function currency(string $code): string
    {
        $code = strtoupper(trim($code));

        return $code === '' || $code === 'KM' ? 'BAM' : substr($code, 0, 3);
    }

    private function dec(mixed $value, int $scale): string
    {
        return Decimal::round(number_format((float) ($value ?? 0), 8, '.', ''), $scale);
    }
}
