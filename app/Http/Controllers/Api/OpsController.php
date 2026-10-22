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
            $o->intake=$this->orders->intake($o);
            $o->settlement = app(\App\Services\Ops\TransportSettlement::class)->read($o);
            $o->transport_type = $o->load_id?(DB::table('loads')->where('id',$o->load_id)->value('transport_type')??'road'):(app(\App\Services\Ops\OpsOrders::class)->intake($o)['department']??'road');
            $o->flow_type = DB::table('ops_transport_flows')->where('order_id', $o->id)->value('transport_type') ?? 'road';
            if($o->load_id&&DB::table('loads')->where('id',$o->load_id)->value('for_storage'))$o->transport_type='warehouse';
            $legs=json_decode(DB::table('ops_transport_flows')->where('order_id',$o->id)->value('legs')??'[]',true);
            $o->departments=array_values(array_unique(array_filter([$o->transport_type,...array_column($legs,'mode')])));
        }

        return $this->response(['orders' => $orders,
            'status_counts' => DB::table('ops_orders')->where('company_id', $id)->selectRaw('status, COUNT(*) as orders')->groupBy('status')->pluck('orders', 'status'),
            'partners' => DB::table('accounting_partners')->where('company_id', $id)->orderBy('name')->get(['id', 'name', 'tax_number']),
            'departments'=>app(\App\Services\Ops\OpsDepartments::class)->forUser($id,(int)$r->user()->id),
            'members' => DB::table('users')->whereIn('id', DB::table('company_user')->where('company_id', $id)->where('status', 'active')->pluck('user_id')
                ->push(DB::table('companies')->where('id', $id)->value('owner_user_id')))->get(['id', 'name'])]);
    }

    public function show(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $order = DB::table('ops_orders')->where('company_id', $id)->where('id', $r->route('order'))->first() ?? abort(404);
        $order->intake=$this->orders->intake($order);
        $items = DB::table('ops_order_items')->where('order_id', $order->id)->orderBy('position')->get();

        return $this->response(['order' => $order, 'items' => [],
            'work_logs' => DB::table('ops_work_logs')->leftJoin('users', 'users.id', '=', 'ops_work_logs.user_id')->whereIn('order_item_id', $items->pluck('id'))
                ->orderByDesc('work_date')->get(['ops_work_logs.*', 'users.name as user_name']),
            'events' => DB::table('ops_events')->leftJoin('users', 'users.id', '=', 'ops_events.user_id')->where('order_id', $order->id)->orderBy('occurred_at')->get(['ops_events.*', 'users.name as user_name']),
            'documents' => DB::table('ops_order_documents')->join('documents','documents.id','=','ops_order_documents.document_id')->where('order_id',$order->id)->get(['documents.id','documents.name','ops_order_documents.role']),
            'source_links'=>['workspace_id'=>$order->workspace_id,'load_id'=>$order->load_id,'crm_document_id'=>$order->crm_document_id,'accepted_offer_id'=>$order->workspace_id?DB::table('shipment_workspace')->where('id',$order->workspace_id)->value('accepted_offer_id'):null],
            'settlement' => app(\App\Services\Ops\TransportSettlement::class)->read($order),
            'flow' => app(\App\Services\Ops\TransportFlow::class)->read($order),
            'workspace' => $order->workspace_id ? DB::table('shipment_workspace')->where('id', $order->workspace_id)->first(['id', 'reference', 'status']) : null]);
    }

    public function store(Request $r): JsonResponse
    {
        $id = $this->company($r);
        $data = $r->validate(['title' => ['required', 'string', 'max:255'], 'template_id' => ['prohibited'], 'customer_partner_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:255'], 'responsible_user_id' => ['nullable', 'integer'], 'currency' => ['prohibited'], 'intake_currency'=>['sometimes', 'string', 'in:BAM,EUR,USD,GBP,CHF'],
            'agreed_revenue' => ['prohibited'], 'planned_start_at' => ['nullable', 'date'], 'planned_end_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:5000'],
            'kind'=>['sometimes',Rule::in(['order','inquiry'])],'source'=>['sometimes',Rule::in(['manual','external'])],
            'department'=>['sometimes',Rule::in(\App\Services\Ops\TransportFlow::MODES)],'external_reference'=>['nullable','string','max:120'],
            'customer_link_type'=>['required_with:customer_link_id',Rule::in(['partner','customer','company'])],'customer_link_id'=>['nullable','integer','min:1'],
            'operations'=>['sometimes','array','min:1'],'operations.*'=>['string','distinct',Rule::in(array_keys(OpsOrders::INTAKE_OPERATIONS))],
            'document_ids'=>['sometimes','array','max:10'],'document_ids.*'=>['integer','distinct'],'cmr_document_id'=>['nullable','integer'],
            'route'=>['sometimes','array:from,to'],'route.from'=>['nullable','string','max:255'],'route.to'=>['nullable','string','max:255'],
            'cargo'=>['sometimes','array:goods_type,weight_kg,volume_m3,pallets'],'cargo.goods_type'=>['nullable','string','max:255'],
            'cargo.weight_kg'=>['nullable','numeric','min:0'],'cargo.volume_m3'=>['nullable','numeric','min:0'],'cargo.pallets'=>['nullable','integer','min:0']]);

        $this->schedule($data['planned_start_at'] ?? null, $data['planned_end_at'] ?? null);
        $data['currency']=$data['intake_currency']??'BAM';unset($data['intake_currency']);
        return $this->response($this->orders->register($id, $r->user()->id, $data), 201);
    }

    public function syncBookings(Request $r): JsonResponse
    {
        $id=$this->company($r);abort_unless(app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(),$id),403);
        return $this->response($this->orders->syncBookings($id,(int)$r->user()->id));
    }

    public function intakeOptions(Request $r): JsonResponse
    {
        $company=$this->company($r);$data=$r->validate(['search'=>['nullable','string','max:100']]);$term=trim($data['search']??'');
        $options=DB::table('accounting_partners')->where('company_id',$company)->when($term,fn($q)=>$q->where('name','like','%'.$term.'%'))->orderBy('name')->limit(20)->get(['id','name'])->map(fn($v)=>['id'=>$v->id,'name'=>$v->name,'type'=>'partner'])->all();
        if(mb_strlen($term)>=2){
            foreach(DB::table('companies')->where('name','like','%'.$term.'%')->orderBy('name')->limit(15)->get(['id','name']) as $v)$options[]=['id'=>$v->id,'name'=>$v->name,'type'=>'company'];
            foreach(DB::table('customers')->where('company_name','like','%'.$term.'%')->orderBy('company_name')->limit(15)->get(['id','company_name']) as $v)$options[]=['id'=>$v->id,'name'=>$v->company_name,'type'=>'customer'];
        }
        return $this->response(['customers'=>$options,'operations'=>OpsOrders::INTAKE_OPERATIONS,'documents'=>DB::table('documents')->where('uploaded_by_user_id',$r->user()->id)->whereNull('load_id')->whereNull('vehicle_id')->whereNull('user_id')->whereNull('load_draft_id')->where('type','CMR')->latest('id')->limit(20)->get(['id','name'])]);
    }

    public function scanIntake(Request $r): JsonResponse
    {
        $this->company($r);$data=$r->validate(['document_id'=>['required','integer']]);
        $doc=DB::table('documents')->where('id',$data['document_id'])->where('uploaded_by_user_id',$r->user()->id)->first()??abort(404);
        abort_if(!empty($doc->load_id)||!empty($doc->vehicle_id)||!empty($doc->user_id)||!empty($doc->load_draft_id),403,'Use your own archive document.');
        $disk=\Illuminate\Support\Facades\Storage::disk('local');$path='documents/'.$doc->path;
        abort_unless($disk->exists($path),422,'The source document is unavailable.');
        abort_if($disk->size($path)>10*1024*1024,422,'Scanning supports files up to 10 MB.');
        $mime=$disk->mimeType($path);
        abort_unless(in_array($mime,['application/pdf','image/jpeg','image/png','image/webp','text/plain'],true),422,'Use PDF, an image, or plain text for scanning. Other files can be attached and entered manually.');
        abort_unless(config('services.openrouter.api_key'),503,'Document scanning is unavailable. The file is saved; enter and review the data manually.');
        $scan=app(\App\Services\OpenRouterLoadScanner::class)->scan([['filename'=>$doc->name,'mimeType'=>$mime,'base64'=>base64_encode($disk->get($path))]]);
        return $this->response(['scan'=>$scan,'requires_review'=>true]);
    }

    public function confirmInquiry(Request $r): JsonResponse
    {
        $company=$this->company($r);$data=$r->validate(['revision'=>['required','integer','min:0']]);
        return $this->response($this->orders->confirmInquiry($company,$r->user()->id,(int)$r->route('order'),$data['revision']));
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
        if(isset($data['status'])&&$data['status']!=='open'){$this->orders->assertConfirmed($order);app(\App\Services\Ops\OpsPortal::class)->assertDocumentsReady($order);}
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
        if(in_array($data['event_type'],['dispatched','loaded','border','customs_cleared','delivered','pod'],true))app(\App\Services\Ops\CargoReview::class)->assertUnblocked(app(\App\Services\OperationalCompliance::class)->order($id,(int)$r->route('order'))->load_id);
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
