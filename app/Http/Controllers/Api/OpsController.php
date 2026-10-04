<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingAccess;
use App\Services\Ops\OpsOrders;
use App\Services\Ops\OpsPantheonSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** FreightBook Ops work orders. Users change local data only; PANTHEON is reached through OpsPantheonSync. */
final class OpsController extends Controller
{
    public function __construct(private AccountingAccess $access, private OpsOrders $orders, private OpsPantheonSync $sync) {}

    private function company(Request $r, string $ability = 'ops'): int
    {
        $id = (int) $r->route('company');
        $this->access->authorize($r->user(), $id, $ability);

        return $id;
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => 'Ops operation completed.', 'errors' => [], 'meta' => []], $status);
    }

    public function overview(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $f = $r->validate(['status' => ['nullable', Rule::in(OpsOrders::STATUSES)], 'search' => ['nullable', 'string', 'max:100']]);
        $orders = DB::table('ops_orders')->where('company_id', $id)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$s.'%')->orWhere('title', 'like', '%'.$s.'%')->orWhere('customer_name', 'like', '%'.$s.'%')))
            ->orderByDesc('id')->limit(300)->get();
        $links = DB::table('ops_pantheon_links')->where('company_id', $id)->where('entity_type', 'order')->whereIn('local_id', $orders->pluck('id'))->get()->keyBy('local_id');
        foreach ($orders as $o) {
            $o->pantheon_key = $links->get($o->id)?->pantheon_key;
            $o->pantheon_pending = ! $links->has($o->id) || (int) $links->get($o->id)->local_revision < (int) $o->revision;
        }

        return $this->response(['orders' => $orders,
            'templates' => DB::table('ops_service_templates')->where('company_id', $id)->orderBy('code')->get()->map(function ($t) {
                $t->items = DB::table('ops_service_template_items')->where('template_id', $t->id)->orderBy('position')->get();

                return $t;
            }),
            'status_counts' => DB::table('ops_orders')->where('company_id', $id)->selectRaw('status, COUNT(*) as orders')->groupBy('status')->pluck('orders', 'status'),
            'partners' => DB::table('accounting_partners')->where('company_id', $id)->orderBy('name')->get(['id', 'name', 'tax_number']),
            'members' => DB::table('users')->whereIn('id', DB::table('company_user')->where('company_id', $id)->where('status', 'active')->pluck('user_id')
                ->push(DB::table('companies')->where('id', $id)->value('owner_user_id')))->get(['id', 'name']),
            'pantheon' => $this->sync->settings($id)]);
    }

    public function show(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $order = DB::table('ops_orders')->where('company_id', $id)->where('id', $r->route('order'))->first() ?? abort(404);
        $items = DB::table('ops_order_items')->where('order_id', $order->id)->orderBy('position')->get();

        return $this->response(['order' => $order, 'items' => $items,
            'work_logs' => DB::table('ops_work_logs')->leftJoin('users', 'users.id', '=', 'ops_work_logs.user_id')->whereIn('order_item_id', $items->pluck('id'))
                ->orderByDesc('work_date')->get(['ops_work_logs.*', 'users.name as user_name']),
            'events' => DB::table('ops_events')->leftJoin('users', 'users.id', '=', 'ops_events.user_id')->where('order_id', $order->id)->orderBy('occurred_at')->get(['ops_events.*', 'users.name as user_name']),
            'documents' => DB::table('ops_order_documents')->leftJoin('invoices', 'invoices.id', '=', 'ops_order_documents.invoice_id')->leftJoin('documents', 'documents.id', '=', 'ops_order_documents.document_id')
                ->where('order_id', $order->id)->get(['ops_order_documents.*', 'invoices.number as invoice_number', 'invoices.total as invoice_total', 'invoices.currency as invoice_currency', 'documents.name as document_name']),
            'margin' => $this->orders->margin($order->id),
            'workspace' => $order->workspace_id ? DB::table('shipment_workspace')->where('id', $order->workspace_id)->first(['id', 'reference', 'status', 'agreed_amount', 'currency']) : null,
            'pantheon' => DB::table('ops_pantheon_links')->where('company_id', $id)->where('entity_type', 'order')->where('local_id', $order->id)->first()]);
    }

    public function store(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['title' => ['required', 'string', 'max:255'], 'template_id' => ['nullable', 'integer'], 'customer_partner_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:255'], 'responsible_user_id' => ['nullable', 'integer'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'agreed_revenue' => ['nullable', 'numeric', 'min:0'], 'planned_start_at' => ['nullable', 'date'], 'planned_end_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:5000']]);

        return $this->response($this->orders->create($id, $r->user()->id, $data), 201);
    }

    public function update(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['title' => ['sometimes', 'string', 'max:255'], 'responsible_user_id' => ['sometimes', 'nullable', 'integer'], 'priority' => ['sometimes', 'integer', 'between:1,15'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'], 'planned_end_at' => ['sometimes', 'nullable', 'date'], 'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['open', 'dispatched', 'in_progress'])], 'realised_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'damaged_qty' => ['sometimes', 'nullable', 'numeric', 'min:0']]);
        $order = DB::table('ops_orders')->where('company_id', $id)->where('id', $r->route('order'))->first() ?? abort(404);
        abort_if(in_array($order->status, ['closed', 'cancelled'], true), 422, 'A closed or cancelled work order cannot be changed.');
        DB::table('ops_orders')->where('id', $order->id)->update($data + ['revision' => $order->revision + 1, 'updated_at' => now()]);

        return $this->response(DB::table('ops_orders')->find($order->id));
    }

    public function item(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['id' => ['nullable', 'integer'], 'item_type' => ['required_without:id', Rule::in(OpsOrders::ITEM_TYPES)], 'item_code' => ['required_without:id', 'string', 'max:30'],
            'description' => ['required_without:id', 'string', 'max:160'], 'unit' => ['sometimes', 'string', 'max:10'], 'planned_qty' => ['sometimes', 'numeric', 'min:0'],
            'actual_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'planned_price' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'actual_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'supplier_partner_id' => ['sometimes', 'nullable', 'integer'], 'finished' => ['sometimes', 'boolean']]);

        return $this->response($this->orders->saveItem($id, $r->user()->id, (int) $r->route('order'), $data));
    }

    public function work(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['order_item_id' => ['required', 'integer'], 'work_date' => ['required', 'date'], 'minutes' => ['required', 'numeric', 'min:0', 'max:100000'],
            'downtime_minutes' => ['nullable', 'numeric', 'min:0'], 'downtime_type' => ['nullable', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->response($this->orders->logWork($id, $r->user()->id, (int) $r->route('order'), $data));
    }

    public function event(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['event_type' => ['required', Rule::in(OpsOrders::EVENTS)], 'occurred_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500'], 'document_id' => ['nullable', 'integer']]);
        if (! empty($data['document_id'])) {
            abort_unless(DB::table('documents')->where('id', $data['document_id'])->where('uploaded_by_user_id', $r->user()->id)->exists(), 422, 'Attach a document you uploaded.');
        }

        return $this->response($this->orders->event($id, $r->user()->id, (int) $r->route('order'), $data));
    }

    public function close(Request $r): JsonResponse
    {
        return $this->response($this->orders->close($this->company($r), $r->user()->id, (int) $r->route('order')));
    }

    /** Templates are copied into each new order, so changing a template never rewrites existing orders. */
    public function template(Request $r): JsonResponse
    {
        $id = $this->company($r, 'setup');
        $data = $r->validate(['id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:160'], 'transport_type' => ['nullable', 'string', 'max:60'],
            'active' => ['required', 'boolean'], 'items' => ['array', 'max:60'], 'items.*.item_type' => ['required', Rule::in(OpsOrders::ITEM_TYPES)], 'items.*.item_code' => ['required', 'string', 'max:30'],
            'items.*.description' => ['required', 'string', 'max:160'], 'items.*.unit' => ['required', 'string', 'max:10'], 'items.*.planned_qty' => ['required', 'numeric', 'min:0'],
            'items.*.planned_price' => ['nullable', 'numeric', 'min:0']]);

        return $this->response(DB::transaction(function () use ($id, $data, $r) {
            $values = collect($data)->only(['code', 'name', 'transport_type', 'active'])->all() + ['updated_at' => now()];
            if (! empty($data['id'])) {
                abort_unless(DB::table('ops_service_templates')->where('company_id', $id)->where('id', $data['id'])->exists(), 404);
                DB::table('ops_service_templates')->where('id', $data['id'])->update($values);
                $templateId = (int) $data['id'];
                DB::table('ops_service_template_items')->where('template_id', $templateId)->delete();
            } else {
                $templateId = DB::table('ops_service_templates')->insertGetId($values + ['company_id' => $id, 'created_by' => $r->user()->id, 'created_at' => now()]);
            }
            foreach (array_values($data['items'] ?? []) as $n => $item) {
                DB::table('ops_service_template_items')->insert(['template_id' => $templateId, 'position' => $n + 1, 'item_type' => $item['item_type'], 'item_code' => $item['item_code'],
                    'description' => $item['description'], 'unit' => $item['unit'], 'planned_qty' => $item['planned_qty'], 'planned_price' => $item['planned_price'] ?? null]);
            }

            return DB::table('ops_service_templates')->find($templateId);
        }));
    }

    public function pantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $settings = $this->sync->settings($id);
        if ($settings) {
            // Members are listed so each SmartFreight user can be mapped to a PANTHEON worker (tHR_Prsn).
            $settings->members = DB::table('users')->whereIn('id', DB::table('company_user')->where('company_id', $id)->where('status', 'active')->pluck('user_id')
                ->push(DB::table('companies')->where('id', $id)->value('owner_user_id')))->get(['id', 'name']);
        }

        return $this->response($settings);
    }

    public function savePantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $data = $r->validate(['sync_enabled' => ['required', 'boolean'], 'order_doc_type' => ['nullable', 'regex:/^[0-9A-Z]{4}$/'], 'push_orders_from' => ['nullable', 'date'],
            'default_worker' => ['nullable', 'string', 'max:30'], 'worker_map' => ['nullable', 'array'], 'worker_map.*' => ['nullable', 'string', 'max:30']]);

        return $this->response($this->sync->save($id, $r->user()->id, $data));
    }

    /** write=false is a dry run: it shows what would be pushed and why an order waits, without writing PANTHEON. */
    public function syncPantheon(Request $r): JsonResponse
    {
        $id = $this->company($r, 'integrations');
        $data = $r->validate(['write' => ['required', 'boolean']]);

        return $this->response($this->sync->sync($id, (bool) $data['write']));
    }
}
