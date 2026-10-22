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

/** Operational work orders. Connector administration is routed separately through Company integrations. */
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
        foreach ($orders as $o) {
            $o->settlement = app(\App\Services\Ops\TransportSettlement::class)->read($o);
            $o->transport_type = DB::table('ops_transport_flows')->where('order_id', $o->id)->value('transport_type') ?? 'road';
        }

        return $this->response(['orders' => $orders,
            'status_counts' => DB::table('ops_orders')->where('company_id', $id)->selectRaw('status, COUNT(*) as orders')->groupBy('status')->pluck('orders', 'status'),
            'partners' => DB::table('accounting_partners')->where('company_id', $id)->orderBy('name')->get(['id', 'name', 'tax_number']),
            'members' => DB::table('users')->whereIn('id', DB::table('company_user')->where('company_id', $id)->where('status', 'active')->pluck('user_id')
                ->push(DB::table('companies')->where('id', $id)->value('owner_user_id')))->get(['id', 'name'])]);
    }

    public function show(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $order = DB::table('ops_orders')->where('company_id', $id)->where('id', $r->route('order'))->first() ?? abort(404);
        $items = DB::table('ops_order_items')->where('order_id', $order->id)->orderBy('position')->get();

        return $this->response(['order' => $order, 'items' => [],
            'work_logs' => DB::table('ops_work_logs')->leftJoin('users', 'users.id', '=', 'ops_work_logs.user_id')->whereIn('order_item_id', $items->pluck('id'))
                ->orderByDesc('work_date')->get(['ops_work_logs.*', 'users.name as user_name']),
            'events' => DB::table('ops_events')->leftJoin('users', 'users.id', '=', 'ops_events.user_id')->where('order_id', $order->id)->orderBy('occurred_at')->get(['ops_events.*', 'users.name as user_name']),
            'documents' => DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)->get(['documents.id','documents.name','ops_order_documents.role']),
            'settlement' => app(\App\Services\Ops\TransportSettlement::class)->read($order),
            'flow' => app(\App\Services\Ops\TransportFlow::class)->read($order),
            'workspace' => $order->workspace_id ? DB::table('shipment_workspace')->where('id', $order->workspace_id)->first(['id', 'reference', 'status']) : null]);
    }

    public function store(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['title' => ['required', 'string', 'max:255'], 'template_id' => ['prohibited'], 'customer_partner_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:255'], 'responsible_user_id' => ['nullable', 'integer'], 'currency' => ['prohibited'],
            'agreed_revenue' => ['prohibited'], 'planned_start_at' => ['nullable', 'date'], 'planned_end_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:5000']]);

        $this->schedule($data['planned_start_at'] ?? null, $data['planned_end_at'] ?? null);
        return $this->response($this->orders->create($id, $r->user()->id, $data), 201);
    }

    public function update(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['title' => ['sometimes', 'string', 'max:255'], 'responsible_user_id' => ['sometimes', 'nullable', 'integer'], 'priority' => ['sometimes', 'integer', 'between:1,15'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'], 'planned_end_at' => ['sometimes', 'nullable', 'date'], 'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['open', 'dispatched', 'in_progress'])], 'realised_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'damaged_qty' => ['sometimes', 'nullable', 'numeric', 'min:0']]);
        return DB::transaction(function () use ($id, $data, $r) {
        if (array_key_exists('responsible_user_id', $data)) app(\App\Services\OperationalCompliance::class)->member($id, $data['responsible_user_id']);
        $order = DB::table('ops_orders')->where('company_id', $id)->where('id', $r->route('order'))->lockForUpdate()->first() ?? abort(404);
        abort_if(in_array($order->status, ['closed', 'cancelled'], true), 422, 'A closed or cancelled work order cannot be changed.');
        $this->schedule(array_key_exists('planned_start_at',$data) ? $data['planned_start_at'] : $order->planned_start_at, array_key_exists('planned_end_at',$data) ? $data['planned_end_at'] : $order->planned_end_at);
        DB::table('ops_orders')->where('id', $order->id)->update($data + ['revision' => $order->revision + 1, 'updated_at' => now()]);

        return $this->response(DB::table('ops_orders')->find($order->id));
        });
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

    public function reopen(Request $r): JsonResponse
    {
        $company=$this->company($r);
        $data=$r->validate(['revision'=>['required','integer','min:0'],'reason'=>['required','string','min:5','max:1000']]);
        return DB::transaction(function() use($company,$data,$r) {
            $ops=app(\App\Services\OperationalCompliance::class);
            $order=$ops->order($company,(int)$r->route('order'),true);
            abort_unless((int)$order->revision === $data['revision'],409,'Nalog je izmijenjen. Osvježite pregled.');
            abort_unless($order->status === 'closed',422,'Samo završen nalog može se ponovo otvoriti.');
            DB::table('ops_orders')->where('id',$order->id)->update(['status'=>'in_progress','finished_at'=>null,'revision'=>$order->revision+1,'updated_at'=>now()]);
            $ops->audit($company,'ops_order',$order->id,$r->user()->id,'reopened_for_review',$order,$data);
            return $this->response(DB::table('ops_orders')->find($order->id));
        });
    }

    private function schedule(?string $start, ?string $end): void
    {
        abort_if($start && $end && strtotime($end)<strtotime($start),422,'Završetak ne može biti prije početka.');
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
