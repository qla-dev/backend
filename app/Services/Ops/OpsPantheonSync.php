<?php

namespace App\Services\Ops;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\PantheonConnector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only code that writes FreightBook Ops work orders to PANTHEON.
 *
 * Agent notes (keep these rules when extending):
 * - SmartFreight is the master. Users never write PANTHEON directly; they change ops_orders and this
 *   sync pushes revisions PANTHEON has not seen yet (ops_pantheon_links.local_revision).
 * - Push: ops_orders -> tHF_WOEx (acDocType = ops_pantheon_sync.order_doc_type, key yy+type+7 digits under
 *   UPDLOCK), ops_order_items cost/operation lines -> tHF_WOExItem (M = cost/material, D = operation).
 *   Revenue lines are not pushed: revenue is the outgoing invoice, which Accounting syncs as a posting.
 * - The acNote marker SF:{company}:ops:{id} lets a retry relink a header written before a failure.
 * - Pull: PANTHEON is the master only for closing (acStatusMF Z) and the produced quantity.
 * - Never: delete in PANTHEON, change a Z (closed) work order, write when allow_write is off,
 *   write 2005/6400/6600/6100 documents (finance goes through the Accounting sync).
 * - Item codes must exist in tHE_SetItem; a missing code is reported and that order waits.
 */
class OpsPantheonSync
{
    public const STATUS_MF = ['open' => 'O', 'dispatched' => 'D', 'in_progress' => 'P', 'partially_closed' => 'R', 'closed' => 'Z', 'cancelled' => 'R'];

    public function __construct(private PantheonConnector $pantheon, private AccountingLedger $ledger) {}

    public function settings(int $companyId): ?object
    {
        $row = DB::table('ops_pantheon_sync')->where('company_id', $companyId)->first();
        if ($row) {
            $row->last_sync_summary = json_decode((string) ($row->last_sync_summary ?? ''), true);
        }

        return $row;
    }

    public function save(int $companyId, int $actor, array $data): ?object
    {
        $exists = DB::table('ops_pantheon_sync')->where('company_id', $companyId)->exists();
        DB::table('ops_pantheon_sync')->updateOrInsert(['company_id' => $companyId], collect($data)->only(['sync_enabled', 'order_doc_type', 'push_orders_from'])->all()
            + ['updated_by' => $actor, 'updated_at' => now()] + ($exists ? [] : ['created_at' => now()]));
        $this->ledger->audit($companyId, $actor, 'pantheon', $companyId, 'ops_sync_saved', $data);

        return $this->settings($companyId);
    }

    /** One push + pull cycle. Safe to repeat: links and the acNote marker prevent duplicates. */
    public function sync(int $companyId, bool $write = true): array
    {
        $state = DB::table('ops_pantheon_sync')->where('company_id', $companyId)->first();
        $connector = DB::table('accounting_pantheon_connectors')->where('company_id', $companyId)->first();
        $this->ledger->require($state && $connector, 'PANTHEON sync is not configured for work orders.');
        $remote = $this->pantheon->connection($companyId);
        $summary = ['pushed' => [], 'updated' => [], 'relinked' => [], 'waiting' => [], 'pulled_closed' => [], 'skipped_closed_remote' => []];
        try {
            $canWrite = $write && $connector->allow_write && $state->sync_enabled && $state->order_doc_type && $state->push_orders_from;
            $orders = DB::table('ops_orders')->where('company_id', $companyId)->where('created_at', '>=', $state->push_orders_from ?? '9999-12-31')->orderBy('id')->get();
            $links = DB::table('ops_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'order')->get()->keyBy('local_id');
            foreach ($orders as $order) {
                $link = $links->get($order->id);
                if ($link && (int) $link->local_revision >= (int) $order->revision) {
                    continue;
                }
                $problems = $this->problems($companyId, $remote, $order);
                if ($problems) {
                    $summary['waiting'][] = ['order_id' => $order->id, 'reference' => $order->reference, 'problems' => $problems];

                    continue;
                }
                if (! $canWrite) {
                    $summary['waiting'][] = ['order_id' => $order->id, 'reference' => $order->reference, 'problems' => ['write_disabled']];

                    continue;
                }
                $result = $this->push($companyId, $connector, $state, $remote, $order, $link);
                $summary[$result['action']][] = ['order_id' => $order->id, 'reference' => $order->reference, 'pantheon_key' => $result['key']];
            }
            $this->pull($companyId, $remote, $summary);
            $this->state($companyId, 'ok', null, $summary);

            return ['ok' => true] + $summary;
        } catch (\Throwable $e) {
            $this->state($companyId, 'failed', mb_substr($e->getMessage(), 0, 500), null);
            throw $e;
        }
    }

    private function push(int $companyId, object $connector, object $state, ConnectionInterface $remote, object $order, ?object $link): array
    {
        $header = $this->pantheon->remoteTable($companyId, 'tHF_WOEx');
        $marker = 'SF:'.$companyId.':ops:'.$order->id;
        $key = $link?->pantheon_key ?? trim((string) $remote->table($header)->where('acNote', 'like', $marker.'%')->value('acKey'));
        $action = $link ? 'updated' : ($key !== '' ? 'relinked' : 'pushed');
        $items = DB::table('ops_order_items')->where('order_id', $order->id)->whereIn('item_type', ['cost', 'operation'])->orderBy('position')->get();
        $subject = $order->customer_partner_id ? (string) DB::table('accounting_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'partner')
            ->where('local_id', $order->customer_partner_id)->value('pantheon_key') : '';
        $template = $order->template_id ? DB::table('ops_service_templates')->where('id', $order->template_id)->value('code') : null;
        $clerk = (int) $connector->clerk_id;
        $values = ['acIdent' => mb_substr((string) ($template ?? ''), 0, 16), 'acName' => mb_substr((string) ($order->title ?? $order->reference), 0, 80), 'acUM' => mb_substr((string) $order->unit, 0, 3),
            'anPlanQty' => (string) $order->planned_qty, 'anProducedQty' => (string) ($order->realised_qty ?? 0), 'acStatusMF' => self::STATUS_MF[$order->status] ?? 'O',
            'acStatus' => $order->status === 'closed' ? 'I' : 'N', 'anPriority' => (int) $order->priority, 'adSchedStartTime' => $order->planned_start_at, 'adSchedEndTime' => $order->planned_end_at,
            'acReceiver' => $subject, 'acConsignee' => $subject, 'acDept' => (string) ($order->department ?? ''),
            'acNote' => $marker.' '.$order->reference.($order->note ? "\n".$order->note : ''), 'anUserChg' => $clerk, 'adTimeChg' => now()];

        $key = $remote->transaction(function () use ($remote, $header, $state, $key, $values, $clerk, $items, $companyId) {
            if ($key === '') {
                $prefix = now()->format('y').$state->order_doc_type;
                $last = trim((string) $remote->table($header)->lockForUpdate()->where('acDocType', $state->order_doc_type)->where('acKey', 'like', $prefix.'%')->orderByDesc('acKey')->value('acKey'));
                $key = $prefix.str_pad((string) ($last !== '' ? (int) substr($last, strlen($prefix)) + 1 : 1), 7, '0', STR_PAD_LEFT);
                $remote->table($header)->insert($values + ['acKey' => $key, 'acDocType' => $state->order_doc_type, 'acDocTypeView' => $state->order_doc_type, 'adDate' => now()->toDateString(),
                    'acKeyView' => substr($key, 0, 2).'-'.$state->order_doc_type.'-'.substr($key, -6), 'acCreateFrom' => 'N', 'anUserIns' => $clerk]);
            } else {
                // A work order PANTHEON already closed is never changed from here.
                $remoteStatus = trim((string) $remote->table($header)->lockForUpdate()->where('acKey', $key)->value('acStatusMF'));
                if ($remoteStatus === 'Z') {
                    return $key;
                }
                $remote->table($header)->where('acKey', $key)->update($values);
            }
            $itemTable = $this->pantheon->remoteTable($companyId, 'tHF_WOExItem');
            foreach ($items as $item) {
                $line = ['acIdent' => mb_substr($item->item_code, 0, 16), 'acDescr' => mb_substr($item->description, 0, 80), 'acOperationType' => $item->item_type === 'operation' ? 'D' : 'M',
                    'acUM' => mb_substr((string) $item->unit, 0, 3), 'anPlanQty' => (float) $item->planned_qty, 'anQty' => (float) ($item->actual_qty ?? $item->planned_qty),
                    'anPrice' => (float) ($item->actual_price ?? $item->planned_price ?? 0), 'acIssueFinished' => $item->finished ? 'Y' : 'N', 'anUserChg' => $clerk];
                if ($remote->table($itemTable)->where('acKey', $key)->where('anNo', $item->position)->exists()) {
                    $remote->table($itemTable)->where('acKey', $key)->where('anNo', $item->position)->update($line);
                } else {
                    $remote->table($itemTable)->insert($line + ['acKey' => $key, 'anNo' => $item->position, 'anVariant' => 0, 'acDelayType' => 'Z', 'anIssuePerc' => 100, 'anUserIns' => $clerk]);
                }
            }

            return $key;
        });
        DB::table('ops_pantheon_links')->updateOrInsert(['company_id' => $companyId, 'entity_type' => 'order', 'local_id' => $order->id],
            ['pantheon_key' => $key, 'local_revision' => $order->revision, 'remote_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return ['action' => $action, 'key' => $key];
    }

    /** PANTHEON is the master for closing: a Z there closes the order here (never the other way round). */
    private function pull(int $companyId, ConnectionInterface $remote, array &$summary): void
    {
        $links = DB::table('ops_pantheon_links')->where('company_id', $companyId)->where('entity_type', 'order')->get();
        foreach ($links->chunk(500) as $chunk) {
            $rows = $remote->table($this->pantheon->remoteTable($companyId, 'tHF_WOEx'))->whereIn('acKey', $chunk->pluck('pantheon_key')->all())
                ->get(['acKey', 'acStatusMF', 'anProducedQty'])->keyBy(fn ($r) => trim($r->acKey));
            foreach ($chunk as $link) {
                $row = $rows->get($link->pantheon_key);
                $order = DB::table('ops_orders')->where('id', $link->local_id)->first();
                if (! $row || ! $order || trim((string) $row->acStatusMF) !== 'Z' || $order->status === 'closed') {
                    continue;
                }
                DB::table('ops_orders')->where('id', $order->id)->update(['status' => 'closed', 'finished_at' => $order->finished_at ?? now(),
                    'realised_qty' => (string) ($row->anProducedQty ?? $order->realised_qty), 'updated_at' => now()]);
                // Same revision as PANTHEON, so the next push does not try to reopen it.
                DB::table('ops_pantheon_links')->where('id', $link->id)->update(['local_revision' => $order->revision, 'remote_changed_at' => now()]);
                $summary['pulled_closed'][] = ['order_id' => $order->id, 'reference' => $order->reference];
            }
        }
    }

    private function problems(int $companyId, ConnectionInterface $remote, object $order): array
    {
        $codes = DB::table('ops_order_items')->where('order_id', $order->id)->whereIn('item_type', ['cost', 'operation'])->pluck('item_code')->all();
        $template = $order->template_id ? DB::table('ops_service_templates')->where('id', $order->template_id)->value('code') : null;
        if ($template) {
            $codes[] = $template;
        } else {
            return ['template_required'];
        }
        $known = $remote->table($this->pantheon->remoteTable($companyId, 'tHE_SetItem'))->whereIn('acIdent', array_values(array_unique($codes)))->pluck('acIdent')->map(fn ($c) => trim($c))->all();
        $missing = array_values(array_diff(array_unique($codes), $known));

        return $missing ? ['unknown_item_codes: '.implode(', ', $missing)] : [];
    }

    private function state(int $companyId, string $status, ?string $error, ?array $summary): void
    {
        DB::table('ops_pantheon_sync')->where('company_id', $companyId)->update(['last_synced_at' => now(), 'last_sync_status' => $status, 'last_sync_error' => $error]
            + ($summary === null ? [] : ['last_sync_summary' => json_encode($summary)]));
    }
}
