<?php

namespace App\Services\Crm;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\Decimal;
use App\Services\Accounting\PantheonConnector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pushes SmartFreight-created CRM offers/orders to PANTHEON as tHE_Order + tHE_OrderItem.
 *
 * Agent notes (same rules as every PANTHEON sync, docs/agents/pantheon-integration.md):
 * - SmartFreight is the master: users edit crm_documents; this class writes PANTHEON on the sync cycle.
 * - Leads are never pushed. An offer goes as status 1 (Ponuda), a confirmed order as 2, a lost or
 *   closed document that is already in PANTHEON as Z (Zaključeno). A new lost/closed one is not pushed.
 * - Values follow PANTHEON: anPV* in KM, anPVOC* in the document currency; EUR uses the fixed
 *   1.95583 rate, other currencies wait. Every line needs a PANTHEON item code and VAT code.
 * - Key yy + doc type + 7 digits under UPDLOCK; acNote marker SF:{company}:crm:{id} relinks a retry.
 * - Once PANTHEON has closed the order (Z) or linked a delivery note to it, it is never changed from here.
 * - PantheonCrmSync (the pull) then reads delivery/invoice progress back for these documents.
 */
class CrmPantheonPush
{
    public const EUR_RATE = '1.95583';

    public const STATUS = ['offer' => '1', 'order' => '2', 'lost' => 'Z', 'closed' => 'Z'];

    public function __construct(private PantheonConnector $pantheon, private AccountingLedger $ledger) {}

    public function push(int $companyId, bool $write = true): array
    {
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($connector !== null, 'Pantheon connector is not configured.');
        $summary = ['pushed' => [], 'updated' => [], 'relinked' => [], 'waiting' => [], 'locked' => []];
        if (! $connector->crm_push_doc_type || ! $connector->crm_push_from) {
            return $summary;
        }
        $remote = $this->pantheon->connection($companyId);
        $canWrite = $write && $connector->allow_write && $connector->crm_push_enabled;
        $documents = DB::table('crm_documents')->where('company_id', $companyId)->where('source', 'smartfreight')->where('created_at', '>=', $connector->crm_push_from)
            ->where(fn ($q) => $q->whereNull('pantheon_revision')->orWhereColumn('pantheon_revision', '<', 'revision'))->orderBy('id')->get();
        foreach ($documents as $document) {
            if (! isset(self::STATUS[$document->stage]) || (! $document->pantheon_key && in_array($document->stage, ['lost', 'closed'], true))) {
                continue; // Leads stay in SmartFreight; a lost offer PANTHEON never saw is not sent.
            }
            $problems = $this->problems($companyId, $remote, $document);
            if ($problems || ! $canWrite) {
                $summary['waiting'][] = ['id' => $document->id, 'title' => $document->title, 'problems' => $problems ?: ['write_disabled']];

                continue;
            }
            $result = $this->write($companyId, $connector, $remote, $document);
            $summary[$result['action']][] = ['id' => $document->id, 'title' => $document->title, 'pantheon_key' => $result['key']];
        }

        return $summary;
    }

    private function write(int $companyId, object $connector, ConnectionInterface $remote, object $document): array
    {
        $orders = $this->pantheon->remoteTable($companyId, 'tHE_Order');
        $itemsTable = $this->pantheon->remoteTable($companyId, 'tHE_OrderItem');
        $marker = 'SF:'.$companyId.':crm:'.$document->id;
        $key = (string) ($document->pantheon_key ?: trim((string) $remote->table($orders)->where('acNote', 'like', $marker.'%')->value('acKey')));
        $action = $document->pantheon_key ? 'updated' : ($key !== '' ? 'relinked' : 'pushed');
        $rate = $document->currency === 'EUR' ? self::EUR_RATE : '1';
        $currency = $document->currency === 'BAM' ? 'KM' : $document->currency;
        $subject = (string) DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->where('local_id', $document->partner_id)->value('pantheon_key');
        $lines = DB::table('crm_document_items')->where('crm_document_id', $document->id)->orderBy('line_no')->get();
        $clerk = (int) $connector->clerk_id;
        $net = Decimal::value((string) $document->net_amount);
        $vat = Decimal::value((string) $document->vat_amount);
        $header = ['acStatus' => self::STATUS[$document->stage], 'acReceiver' => $subject, 'acConsignee' => $subject, 'acContactPrsn' => mb_substr((string) $document->contact_name, 0, 255),
            'adDateValid' => $document->valid_until, 'acCurrency' => $currency, 'anFXRate' => $rate, 'anValue' => $this->km($net, $rate), 'anVAT' => $this->km($vat, $rate),
            'anForPay' => $this->km(bcadd($net, $vat, 2), $rate), 'anCurrValue' => bcadd($net, $vat, 2), 'acDoc1' => mb_substr((string) $document->title, 0, 50),
            'acNote' => $marker.($document->note ? "\n".$document->note : ''), 'anClerk' => $clerk, 'anUserChg' => $clerk, 'adTimeChg' => now()];

        $key = $remote->transaction(function () use ($remote, $orders, $itemsTable, $connector, $document, $key, $header, $lines, $rate, $clerk, $companyId) {
            if ($key === '') {
                $type = $connector->crm_push_doc_type;
                $prefix = now()->format('y').$type;
                $last = trim((string) $remote->table($orders)->lockForUpdate()->where('acDocType', $type)->where('acKey', 'like', $prefix.'%')->orderByDesc('acKey')->value('acKey'));
                $key = $prefix.str_pad((string) ($last !== '' ? (int) substr($last, strlen($prefix)) + 1 : 1), 7, '0', STR_PAD_LEFT);
                $remote->table($orders)->insert($header + ['acKey' => $key, 'acDocType' => $type, 'adDate' => $document->issued_on ?? now()->toDateString(),
                    'acKeyView' => substr($key, 0, 2).'-'.$type.'-'.substr($key, -6), 'anUserIns' => $clerk]);
            } else {
                $row = $remote->table($orders)->lockForUpdate()->where('acKey', $key)->first(['acStatus']);
                $delivered = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_LinkMoveItemOrderItem'))->where('acLnkKey', $key)->exists();
                if (! $row || trim((string) $row->acStatus) === 'Z' || $delivered) {
                    return [$key]; // Closed or already delivered in PANTHEON: never changed from here.
                }
                $remote->table($orders)->where('acKey', $key)->update($header);
            }
            foreach ($lines as $line) {
                $values = $this->line($line, $rate) + ['anUserChg' => $clerk, 'adTimeChg' => now()];
                if ($remote->table($itemsTable)->where('acKey', $key)->where('anNo', $line->line_no)->exists()) {
                    $remote->table($itemsTable)->where('acKey', $key)->where('anNo', $line->line_no)->update($values);
                } else {
                    $remote->table($itemsTable)->insert($values + ['acKey' => $key, 'anNo' => $line->line_no, 'anQtyDispDoc' => 0, 'acLnkKey' => '', 'anLnkNo' => 0, 'anUserIns' => $clerk, 'adTimeIns' => now()]);
                }
            }

            return $key;
        });
        $locked = is_array($key);
        $key = $locked ? $key[0] : $key;
        DB::table('crm_documents')->where('id', $document->id)->update(['pantheon_key' => $key, 'pantheon_revision' => $document->revision, 'pantheon_pushed_at' => now()]);

        return ['action' => $locked ? 'locked' : $action, 'key' => $key];
    }

    private function line(object $line, string $rate): array
    {
        $quantity = (string) $line->quantity;
        $net = Decimal::round(bcmul(bcmul($quantity, (string) $line->unit_price, 8), bcsub('1', bcdiv((string) $line->discount_percent, '100', 8), 8), 8));
        $vat = Decimal::round(bcmul($net, bcdiv((string) ($line->vat_percent ?? '0'), '100', 8), 8));
        $total = bcadd($net, $vat, 2);

        return ['acIdent' => mb_substr((string) $line->item_code, 0, 16), 'acName' => mb_substr((string) $line->name, 0, 80), 'anQty' => $quantity, 'anQtyConverted' => $quantity,
            'acUM' => mb_substr((string) ($line->unit ?? 'KOM'), 0, 3), 'acUMConverted' => mb_substr((string) ($line->unit ?? 'KOM'), 0, 3), 'anPrice' => (string) $line->unit_price,
            'anRebate' => (string) $line->discount_percent, 'acVATCode' => (string) $line->vat_code, 'anVAT' => (string) ($line->vat_percent ?? '0'),
            'anPVValue' => $this->km($net, $rate), 'anPVDiscount' => 0, 'anPVExcise' => 0, 'anPVVATBase' => $this->km($net, $rate), 'anPVVAT' => $this->km($vat, $rate), 'anPVForPay' => $this->km($total, $rate),
            'anPVOCValue' => $net, 'anPVOCDiscount' => 0, 'anPVOCExcise' => 0, 'anPVOCVATBase' => $net, 'anPVOCVAT' => $vat, 'anPVOCForPay' => $total];
    }

    private function problems(int $companyId, ConnectionInterface $remote, object $document): array
    {
        $problems = [];
        if (! in_array($document->currency, ['BAM', 'EUR'], true)) {
            $problems[] = 'exchange_rate_required';
        }
        if (! $document->partner_id || ! DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')->where('local_id', $document->partner_id)->exists()) {
            $problems[] = 'customer_not_in_pantheon';
        }
        $lines = DB::table('crm_document_items')->where('crm_document_id', $document->id)->get(['item_code', 'vat_code']);
        if ($lines->isEmpty()) {
            return [...$problems, 'items_required'];
        }
        $codes = $lines->pluck('item_code')->filter()->unique()->values()->all();
        $known = $codes ? $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetItem'))->whereIn('acIdent', $codes)->pluck('acIdent')->map(fn ($c) => trim($c))->all() : [];
        $missing = array_values(array_diff($codes, $known));
        if ($lines->contains(fn ($l) => ! $l->item_code) || $missing) {
            $problems[] = 'unknown_item_codes: '.(implode(', ', $missing) ?: '—');
        }
        $vatCodes = $lines->pluck('vat_code')->filter()->unique()->values()->all();
        $knownVat = $vatCodes ? $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetTax'))->whereIn('acVATCode', $vatCodes)->pluck('acVATCode')->map(fn ($c) => trim($c))->all() : [];
        if ($lines->contains(fn ($l) => ! $l->vat_code) || array_diff($vatCodes, $knownVat)) {
            $problems[] = 'vat_code_required';
        }

        return $problems;
    }

    private function km(string $amount, string $rate): string
    {
        return Decimal::round(bcmul($amount, $rate, 8));
    }
}
