<?php

namespace App\Services\Ops;

use App\Models\ShipmentWorkspace;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FreightBook Ops work orders (špediterski nalog), the logistics copy of PANTHEON Proizvodnja.
 *
 * Agent notes:
 * - SmartFreight is the source of truth and works without PANTHEON. A load that becomes a booked
 *   shipment (shipment_workspace) becomes a work order here, 1:1. Nothing in this class talks to
 *   PANTHEON; OpsPantheonSync pushes/pulls on its own schedule when a company enables it.
 * - Lines come from the company's service template (cost = subcontractor, operation = internal work,
 *   revenue = what the customer pays). Revenue is invoiced through Accounting ("create from job"),
 *   never from here, so postings are not duplicated.
 * - Closing is atomic and idempotent. Missing actual cost or POD -> partially_closed, not a block.
 * - Every content change bumps `revision`; the sync pushes only revisions PANTHEON has not seen.
 */
class OpsOrders
{
    public const STATUSES = ['open', 'dispatched', 'in_progress', 'partially_closed', 'closed', 'cancelled'];

    public const ITEM_TYPES = ['cost', 'operation', 'revenue'];

    public const EVENTS = ['booked', 'dispatched', 'loaded', 'border', 'customs_cleared', 'delivered', 'pod', 'damage', 'note'];

    public function __construct(private AccountingLedger $ledger) {}

    public static function available(): bool
    {
        static $available;

        return $available ??= Schema::hasTable('ops_orders');
    }

    /** Called when a shipment workspace is booked. Returns the existing order on retry. */
    public function createFromWorkspace(ShipmentWorkspace $workspace): ?object
    {
        if (! self::available() || ! $workspace->provider_company_id) {
            return null; // Independent drivers have no company books; the shipment still works.
        }
        $existing = DB::table('ops_orders')->where('workspace_id', $workspace->id)->first();
        if ($existing) {
            return $existing;
        }
        $companyId = (int) $workspace->provider_company_id;
        $load = DB::table('loads')->where('id', $workspace->load_id)->first();
        $type = $load && ($load->for_storage ?? false) ? 'warehouse' : (string) ($load->transport_type ?? 'road');
        $template = DB::table('ops_service_templates')->where('company_id', $companyId)->where('active', true)
            ->where(fn ($q) => $q->where('transport_type', $type)->orWhereNull('transport_type'))
            ->orderByRaw('CASE WHEN transport_type IS NULL THEN 1 ELSE 0 END')->orderBy('variant')->first();
        $customer = DB::table('customers')->where('user_id', $workspace->customer_user_id)->first();
        $partner = $customer ? DB::table('accounting_partners')->where('company_id', $companyId)->where('customer_id', $customer->id)->first() : null;
        $snapshot = is_array($workspace->load_snapshot) ? $workspace->load_snapshot : (json_decode((string) $workspace->load_snapshot, true) ?: []);
        $stops = collect($load ? DB::table('load_stops')->where('load_id', $load->id)->orderBy('id')->get() : []);
        $crmId = $load->crm_document_id ?? null;

        return DB::transaction(function () use ($workspace, $companyId, $template, $partner, $customer, $snapshot, $stops, $crmId, $load) {
            $id = DB::table('ops_orders')->insertGetId([
                'company_id' => $companyId, 'reference' => $this->nextReference($companyId), 'doc_type' => 'SF00',
                'workspace_id' => $workspace->id, 'load_id' => $workspace->load_id, 'crm_document_id' => $crmId, 'template_id' => $template?->id,
                'customer_partner_id' => $partner?->id, 'customer_name' => $partner->name ?? $customer->company_name ?? DB::table('users')->where('id', $workspace->customer_user_id)->value('name'),
                'title' => mb_substr((string) ($snapshot['title'] ?? $load->title ?? $workspace->reference), 0, 255), 'responsible_user_id' => $workspace->provider_user_id,
                'status' => 'open', 'planned_start_at' => $this->stopTime($stops->first()), 'planned_end_at' => $this->stopTime($stops->last()),
                'currency' => strtoupper((string) $workspace->currency) ?: 'BAM', 'agreed_revenue' => Decimal::value((string) $workspace->agreed_amount),
                'created_by' => $workspace->provider_user_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $position = 0;
            if ($template) {
                foreach (DB::table('ops_service_template_items')->where('template_id', $template->id)->orderBy('position')->get() as $line) {
                    if ($line->item_type === 'revenue') {
                        continue; // Revenue comes from the agreed amount of this shipment, below.
                    }
                    DB::table('ops_order_items')->insert(['order_id' => $id, 'position' => ++$position, 'item_type' => $line->item_type, 'item_code' => $line->item_code,
                        'description' => $line->description, 'unit' => $line->unit, 'planned_qty' => $line->planned_qty, 'planned_price' => $line->planned_price,
                        'supplier_partner_id' => $line->default_supplier_partner_id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('ops_order_items')->insert(['order_id' => $id, 'position' => ++$position, 'item_type' => 'revenue', 'item_code' => $template->code ?? 'TRANSPORT',
                'description' => mb_substr((string) ($snapshot['title'] ?? $workspace->reference), 0, 160), 'unit' => 'KOM', 'planned_qty' => 1,
                'planned_price' => Decimal::value((string) $workspace->agreed_amount, 4), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ops_events')->insert(['order_id' => $id, 'event_type' => 'booked', 'occurred_at' => now(), 'user_id' => $workspace->provider_user_id, 'created_at' => now()]);
            $this->followCrm($crmId, 'order');

            return DB::table('ops_orders')->find($id);
        });
    }

    /** Keeps the work order in step with the shipment status, without ever reopening a closed order. */
    public function followWorkspace(ShipmentWorkspace $workspace): void
    {
        if (! self::available()) {
            return;
        }
        $order = DB::table('ops_orders')->where('workspace_id', $workspace->id)->first();
        if (! $order || in_array($order->status, ['closed', 'cancelled'], true)) {
            return;
        }
        $status = match ($workspace->status) {
            'in_execution' => in_array($order->status, ['open', 'dispatched'], true) ? 'in_progress' : null,
            'cancelled' => 'cancelled',
            default => null,
        };
        if ($workspace->status === 'completed' && ! DB::table('ops_events')->where('order_id', $order->id)->where('event_type', 'delivered')->exists()) {
            DB::table('ops_events')->insert(['order_id' => $order->id, 'event_type' => 'delivered', 'occurred_at' => now(), 'created_at' => now()]);
            DB::table('ops_orders')->where('id', $order->id)->increment('revision');
            $order->revision++;
            $this->followCrm($order->crm_document_id, 'delivered');
        }
        if ($status && $status !== $order->status) {
            DB::table('ops_orders')->where('id', $order->id)->update(['status' => $status, 'revision' => $order->revision + 1, 'updated_at' => now()]);
            DB::table('ops_events')->insert(['order_id' => $order->id, 'event_type' => $status === 'cancelled' ? 'note' : 'dispatched', 'occurred_at' => now(),
                'note' => $status === 'cancelled' ? 'Shipment cancelled' : null, 'created_at' => now()]);
            $this->followCrm($order->crm_document_id, $status === 'in_progress' ? 'in_delivery' : null);
        }
    }

    public function create(int $companyId, int $actor, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $data) {
            if (! empty($data['customer_partner_id'])) {
                $this->ledger->require(DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $data['customer_partner_id'])->exists(), 'Customer does not belong to this company.');
            }
            $template = ! empty($data['template_id']) ? DB::table('ops_service_templates')->where('company_id', $companyId)->where('id', $data['template_id'])->first() : null;
            $this->ledger->require(empty($data['template_id']) || $template !== null, 'Template does not belong to this company.');
            $id = DB::table('ops_orders')->insertGetId(['company_id' => $companyId, 'reference' => $this->nextReference($companyId), 'template_id' => $template?->id,
                'customer_partner_id' => $data['customer_partner_id'] ?? null, 'customer_name' => $data['customer_name'] ?? DB::table('accounting_partners')->where('id', $data['customer_partner_id'] ?? 0)->value('name'),
                'title' => $data['title'], 'responsible_user_id' => $data['responsible_user_id'] ?? $actor, 'currency' => $data['currency'] ?? 'BAM',
                'agreed_revenue' => isset($data['agreed_revenue']) ? Decimal::value((string) $data['agreed_revenue']) : null, 'planned_start_at' => $data['planned_start_at'] ?? null,
                'planned_end_at' => $data['planned_end_at'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $position = 0;
            foreach ($template ? DB::table('ops_service_template_items')->where('template_id', $template->id)->orderBy('position')->get() : [] as $line) {
                DB::table('ops_order_items')->insert(['order_id' => $id, 'position' => ++$position, 'item_type' => $line->item_type, 'item_code' => $line->item_code, 'description' => $line->description,
                    'unit' => $line->unit, 'planned_qty' => $line->planned_qty, 'planned_price' => $line->item_type === 'revenue' && isset($data['agreed_revenue']) ? Decimal::value((string) $data['agreed_revenue'], 4) : $line->planned_price,
                    'supplier_partner_id' => $line->default_supplier_partner_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->ledger->audit($companyId, $actor, 'ops_order', $id, 'ops_created', ['template_id' => $template?->id]);

            return DB::table('ops_orders')->find($id);
        });
    }

    public function saveItem(int $companyId, int $actor, int $orderId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $orderId, $data) {
            $order = $this->editable($companyId, $orderId);
            if (! empty($data['supplier_partner_id'])) {
                $this->ledger->require(DB::table('accounting_partners')->where('company_id', $companyId)->where('id', $data['supplier_partner_id'])->exists(), 'Supplier does not belong to this company.');
            }
            $values = collect($data)->only(['item_type', 'item_code', 'description', 'unit', 'planned_qty', 'actual_qty', 'planned_price', 'actual_price', 'supplier_partner_id', 'finished'])->all();
            if (! empty($data['id'])) {
                $this->ledger->require(DB::table('ops_order_items')->where('order_id', $orderId)->where('id', $data['id'])->exists(), 'Line does not belong to this work order.');
                DB::table('ops_order_items')->where('id', $data['id'])->update($values + ['updated_at' => now()]);
                $id = (int) $data['id'];
            } else {
                $id = DB::table('ops_order_items')->insertGetId($values + ['order_id' => $orderId, 'position' => (int) DB::table('ops_order_items')->where('order_id', $orderId)->max('position') + 1,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $this->touch($order);
            $this->ledger->audit($companyId, $actor, 'ops_order', $orderId, 'ops_item_saved', ['item_id' => $id]);

            return DB::table('ops_order_items')->find($id);
        });
    }

    public function logWork(int $companyId, int $actor, int $orderId, array $data): object
    {
        return DB::transaction(function () use ($companyId, $actor, $orderId, $data) {
            $order = $this->editable($companyId, $orderId);
            $item = DB::table('ops_order_items')->where('order_id', $orderId)->where('id', $data['order_item_id'])->first();
            $this->ledger->require($item !== null && $item->item_type === 'operation', 'Work is logged on an operation line of this work order.');
            $id = DB::table('ops_work_logs')->insertGetId(['order_item_id' => $item->id, 'user_id' => $data['user_id'] ?? $actor, 'work_date' => $data['work_date'],
                'minutes' => Decimal::value((string) $data['minutes']), 'downtime_minutes' => Decimal::value((string) ($data['downtime_minutes'] ?? '0')),
                'downtime_type' => $data['downtime_type'] ?? null, 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            // Actual quantity of an operation is the logged time in its unit (hours or minutes).
            $minutes = (string) DB::table('ops_work_logs')->where('order_item_id', $item->id)->sum('minutes');
            DB::table('ops_order_items')->where('id', $item->id)->update(['actual_qty' => strtoupper($item->unit) === 'MIN' ? Decimal::value($minutes, 4) : Decimal::round(bcdiv($minutes, '60', 8), 4), 'updated_at' => now()]);
            if ($order->status === 'open') {
                DB::table('ops_orders')->where('id', $orderId)->update(['status' => 'in_progress']);
            }
            $this->touch($order);

            return DB::table('ops_work_logs')->find($id);
        });
    }

    public function event(int $companyId, int $actor, int $orderId, array $data): object
    {
        $order = $this->editable($companyId, $orderId);
        if (! empty($data['document_id'])) {
            DB::table('ops_order_documents')->insert(['order_id' => $orderId, 'role' => $data['event_type'] === 'pod' ? 'pod' : 'attachment', 'document_id' => $data['document_id'], 'created_at' => now()]);
        }
        $id = DB::table('ops_events')->insertGetId(['order_id' => $orderId, 'event_type' => $data['event_type'], 'occurred_at' => $data['occurred_at'] ?? now(),
            'user_id' => $actor, 'note' => $data['note'] ?? null, 'created_at' => now()]);
        $this->touch($order);

        return DB::table('ops_events')->find($id);
    }

    /**
     * Atomic, idempotent close. Links the revenue invoice and cost allocations booked in Accounting,
     * then closes or partially closes the order (missing actual cost or POD).
     */
    public function close(int $companyId, int $actor, int $orderId): object
    {
        return DB::transaction(function () use ($companyId, $actor, $orderId) {
            $order = DB::table('ops_orders')->where('company_id', $companyId)->where('id', $orderId)->lockForUpdate()->first() ?? abort(404);
            if ($order->status === 'closed') {
                return $order;
            }
            $this->ledger->require($order->status !== 'cancelled', 'A cancelled work order cannot be closed.');
            if ($order->workspace_id) {
                $this->ledger->require(DB::table('shipment_workspace')->where('id', $order->workspace_id)->value('status') === 'completed', 'Complete the shipment before closing its work order.');
                foreach (DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->where('invoices.company_id', $companyId)
                    ->where('invoice_items.workspace_id', $order->workspace_id)->where('invoices.direction', 'outgoing')->pluck('invoices.id')->unique() as $invoiceId) {
                    $this->document($orderId, 'revenue', (int) $invoiceId);
                    if (DB::table('invoices')->where('id', $invoiceId)->value('issuance_status') === 'issued') {
                        $this->followCrm($order->crm_document_id, 'invoiced');
                    }
                }
                foreach (DB::table('accounting_cost_allocations')->join('invoice_items', 'invoice_items.id', '=', 'accounting_cost_allocations.invoice_item_id')
                    ->where('accounting_cost_allocations.company_id', $companyId)->where('accounting_cost_allocations.workspace_id', $order->workspace_id)->pluck('invoice_items.invoice_id')->unique() as $invoiceId) {
                    $this->document($orderId, 'cost', (int) $invoiceId);
                }
            }
            $problems = [];
            $missingCost = DB::table('ops_order_items')->where('order_id', $orderId)->where('item_type', 'cost')->whereNull('actual_price')->pluck('description')->all();
            if ($missingCost) {
                $problems[] = 'missing_actual_cost: '.implode(', ', $missingCost);
            }
            if ($order->workspace_id && ! DB::table('ops_events')->where('order_id', $orderId)->where('event_type', 'pod')->exists()
                && ! DB::table('ops_order_documents')->where('order_id', $orderId)->where('role', 'pod')->exists()) {
                $problems[] = 'missing_pod';
            }
            $status = $problems ? 'partially_closed' : 'closed';
            DB::table('ops_orders')->where('id', $orderId)->update(['status' => $status, 'close_problems' => $problems ? implode("\n", $problems) : null,
                'finished_at' => $status === 'closed' ? now() : null, 'realised_qty' => $order->realised_qty ?? $order->planned_qty, 'revision' => $order->revision + 1, 'updated_at' => now()]);
            DB::table('ops_order_items')->where('order_id', $orderId)->where('item_type', 'operation')->update(['finished' => true]);
            $this->ledger->audit($companyId, $actor, 'ops_order', $orderId, 'ops_'.$status, ['problems' => $problems]);

            return DB::table('ops_orders')->find($orderId);
        });
    }

    /** Plan, actual and margin per order. Estimates are replaced by actuals, never added to them. */
    public function margin(int $orderId): array
    {
        $plan = ['revenue' => '0.00', 'cost' => '0.00', 'operation' => '0.00'];
        $actual = $plan;
        foreach (DB::table('ops_order_items')->where('order_id', $orderId)->get() as $i) {
            $planned = Decimal::round(bcmul((string) ($i->planned_price ?? 0), (string) $i->planned_qty, 8));
            $plan[$i->item_type] = bcadd($plan[$i->item_type], $planned, 2);
            $real = $i->actual_price !== null ? Decimal::round(bcmul((string) $i->actual_price, (string) ($i->actual_qty ?? $i->planned_qty), 8))
                : ($i->actual_qty !== null && $i->planned_price !== null ? Decimal::round(bcmul((string) $i->planned_price, (string) $i->actual_qty, 8)) : $planned);
            $actual[$i->item_type] = bcadd($actual[$i->item_type], $real, 2);
        }
        $result = fn ($v) => bcsub(bcsub($v['revenue'], $v['cost'], 2), $v['operation'], 2);

        return ['plan' => $plan + ['margin' => $result($plan)], 'actual' => $actual + ['margin' => $result($actual)]];
    }

    private function document(int $orderId, string $role, int $invoiceId): void
    {
        if (! DB::table('ops_order_documents')->where('order_id', $orderId)->where('role', $role)->where('invoice_id', $invoiceId)->exists()) {
            DB::table('ops_order_documents')->insert(['order_id' => $orderId, 'role' => $role, 'invoice_id' => $invoiceId, 'created_at' => now()]);
        }
    }

    private function editable(int $companyId, int $orderId): object
    {
        $order = DB::table('ops_orders')->where('company_id', $companyId)->where('id', $orderId)->lockForUpdate()->first() ?? abort(404);
        // A closed order is final here as in PANTHEON (status Z): corrections go through a new order.
        $this->ledger->require(! in_array($order->status, ['closed', 'cancelled'], true), 'A closed or cancelled work order cannot be changed.');

        return $order;
    }

    private function touch(object $order): void
    {
        DB::table('ops_orders')->where('id', $order->id)->increment('revision', 1, ['updated_at' => now()]);
    }

    /** SmartFreight-owned CRM documents follow the shipment; a later stage never moves back. */
    private function followCrm(?int $crmId, ?string $stage): void
    {
        if (! $crmId || ! $stage || ! Schema::hasTable('crm_documents')) {
            return;
        }
        $order = ['lead' => 0, 'offer' => 1, 'order' => 2, 'in_delivery' => 3, 'delivered' => 4, 'invoiced' => 5];
        $document = DB::table('crm_documents')->where('id', $crmId)->first();
        if ($document && ($order[$document->stage] ?? 9) < $order[$stage]) {
            DB::table('crm_documents')->where('id', $crmId)->update(['stage' => $stage, 'updated_at' => now()]);
        }
    }

    private function nextReference(int $companyId): string
    {
        // Locked per company so two bookings at the same moment cannot take the same number.
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
        $prefix = now()->format('y').'-SF-';
        $last = DB::table('ops_orders')->where('company_id', $companyId)->where('reference', 'like', $prefix.'%')->orderByDesc('reference')->value('reference');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }

    private function stopTime(?object $stop): ?string
    {
        foreach (['window_starts_at', 'window_ends_at'] as $column) {
            if ($stop && isset($stop->{$column}) && $stop->{$column}) {
                return (string) $stop->{$column};
            }
        }

        return null;
    }
}
