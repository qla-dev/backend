<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Accounting\AccountingAccess;
use App\Services\OperationalCompliance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class OperationalComplianceController extends Controller
{
    public function __construct(private AccountingAccess $access, private OperationalCompliance $ops) {}

    private function company(Request $r, string $ability = 'ops'): int
    {
        $id = (int) $r->route('company');
        if (!$r->user()->isSuperAdminOrMaster()) $this->access->authorize($r->user(), $id, $ability);
        return $id;
    }

    private function response(mixed $data, int $status = 200)
    {
        return response()->json(['data' => $data, 'message' => 'Sačuvano.', 'errors' => [], 'meta' => []], $status);
    }

    private function profileAccess(Request $r, bool $write = false): array
    {
        $company = (int) $r->route('company');
        if ($r->user()->isSuperAdminOrMaster()) {
            DB::table('companies')->where('id', $company)->first() ?? abort(404);
            return [$company, true];
        }
        $record = $this->access->member($r->user(), $company);
        $manage = (int) $record->owner_user_id === (int) $r->user()->id
            || in_array('setup', $this->access->abilities($r->user(), $company), true);
        if ($write) abort_unless($manage, 403, 'Postavke vodi vlasnik ili ovlašteni administrator kompanije.');
        return [$company, $manage];
    }

    private function optionalCatalog(object $order): array
    {
        $catalog=\App\Services\Ops\OperationsPlan::EXTRA;
        if(!$order->workspace_id){
            unset($catalog['cargo_documents']);
            foreach(\App\Services\Ops\OpsOrders::INTAKE_OPERATIONS as $key=>$label)$catalog[$key]=[$label,null];
        }
        return $catalog;
    }

    public function show(Request $r)
    {
        $company = $this->company($r);
        $order = $this->ops->order($company, (int) $r->route('order'));
        return $this->response(['optional_catalog'=>$this->optionalCatalog($order),'checklist' => app(\App\Services\Ops\OpsTaskAccess::class)->project($r->user(), $order, $this->ops->read($order)),
            'inspections' => DB::table('ops_inspection_cases')->where('company_id', $company)->where('order_id', $order->id)->when(!app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(), $company), fn($q)=>$q->where('responsible_user_id',$r->user()->id))->get()->map(fn ($x) => $this->decodeCase($x)),
            'documents' => Document::query()->where(function ($q) use ($order) {
                $q->whereIn('id', DB::table('ops_order_documents')->where('order_id',$order->id)->whereNotNull('document_id')->select('document_id'));
                if ($order->load_id) $q->orWhere('load_id',$order->load_id);
            })->get(['id','name'])->map(fn($doc)=>['id'=>$doc->id,'name'=>$doc->name,'linked'=>DB::table('ops_order_documents')->where('order_id',$order->id)->where('document_id',$doc->id)->exists()]),
            'audit' => DB::table('compliance_audit')->where('company_id', $company)->where(function ($q) use ($order) {
                $q->where(fn ($q) => $q->where('entity', 'checklist')->where('entity_id', $order->id))
                    ->orWhere(fn ($q) => $q->where('entity', 'inspection')->whereIn('entity_id', DB::table('ops_inspection_cases')->where('order_id', $order->id)->pluck('id')));
            })->latest('id')->limit(50)->get(['id', 'entity', 'entity_id', 'actor_id', 'action', 'created_at'])]);
    }

    public function cargoReview(Request $r)
    {
        $company=$this->company($r);$order=$this->ops->order($company,(int)$r->route('order'));
        $service=app(\App\Services\Ops\CargoReview::class);
        return app(OpsPortalController::class)->review($r);
    }

    public function optionalTasks(Request $r)
    {
        $company=$this->company($r);
        abort_unless(app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(),$company),403);
        $data=$r->validate(['revision'=>['required','integer'],'fingerprint'=>['required','string','size:64'],'key'=>['required','string','max:100'],'reason'=>['required','string','min:5','max:1000']]);
        return DB::transaction(function()use($r,$company,$data){
            $order=$this->ops->order($company,(int)$r->route('order'),true);
            abort_if(in_array($order->status,['closed','cancelled'],true),422);
            if($order->workspace_id)\App\Models\ShipmentWorkspace::whereKey($order->workspace_id)->lockForUpdate()->firstOrFail();
            $before=$this->ops->read($order,false);
            abort_unless($before['revision']===$data['revision']&&hash_equals($before['fingerprint'],$data['fingerprint']),409,'The plan changed. Refresh before adding work.');
            $items=array_map(fn($item)=>$item+['plan_version'=>2],$before['items']);$index=array_search($data['key'],array_column($items,'key'));
            $catalog=$this->optionalCatalog($order);
            abort_unless(($index!==false&&!empty($items[$index]['optional']))||isset($catalog[$data['key']]),422,'Unknown optional operation.');
            if($data['key']==='sea_si_vgm'||($index!==false&&!empty($items[$index]['verified_sea_only']))){
                $flow=app(\App\Services\Ops\TransportFlow::class)->read($order);
                abort_unless(($flow['transport_type']==='sea'||collect($flow['legs'])->contains('mode','sea'))&&app(\App\Services\Ops\OpsTaskAccess::class)->verifiedSeaOrganizer($order),422,'This operation requires a sea service and its verified organizer.');
            }
            if($index===false){$items[]=['key'=>$data['key'],'label'=>$catalog[$data['key']][0],'status'=>'pending','responsible_user_id'=>$order->responsible_user_id??$r->user()->id,'controlled'=>$data['key']==='cargo_documents','required_documents'=>$data['key']==='cmr'?['CMR']:[]];$index=count($items)-1;}
            if($data['key']==='sea_si_vgm')$items[$index]['verified_sea_only']=true;
            $items[$index]['optional_selected']=true;$items[$index]['requirement_reason']=$data['reason'];$items[$index]['requirement_source']='operator_review';$items[$index]['applicable']=true;
            $context=$before['context'];foreach($items[$index]['applies_when']??[] as $flag)$context[$flag]=true;
            if($flag=$catalog[$data['key']][1]??null)$context[$flag]=true;
            if($order->workspace_id)\App\Models\ShipmentWorkspace::findOrFail($order->workspace_id)->update(['operational_checklist'=>$items]);
            $this->ops->initialize($order);
            DB::table('ops_operational_state')->where('order_id',$order->id)->update(['items'=>$order->workspace_id?null:json_encode($items),'context'=>json_encode($context),'revision'=>$before['revision']+1,'updated_at'=>now()]);
            DB::table('ops_orders')->where('id',$order->id)->increment('revision');
            $this->ops->audit($company,'checklist',$order->id,$r->user()->id,'optional_operation_added',$before,['key'=>$data['key'],'reason'=>$data['reason']]);
            return $this->response($this->ops->read($order));
        });
    }

    public function checklist(Request $r)
    {
        $company = $this->company($r);
        $data = $r->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'items' => ['sometimes', 'array', 'max:100'], 'items.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'items.*.status' => ['required', Rule::in(OperationalCompliance::STATUSES)],
            'items.*.responsible_user_id' => ['nullable', 'integer'], 'items.*.due_date' => ['nullable', 'date'],
            'items.*.next_action' => ['nullable', 'string', 'max:1000'], 'items.*.exception_reason' => ['nullable', 'string', 'max:2000'],
            'items.*.document_ids' => ['sometimes','array','max:30'], 'items.*.document_ids.*' => ['integer','distinct'],
            'items.*.action_value' => ['nullable', 'string', 'max:8000'],
            'context' => ['sometimes', 'array:route,goods,incoterms,inspection_required,dangerous_goods,temperature_controlled,export_required,import_required,transit_required'],
            'context.export_required' => ['sometimes','boolean'], 'context.import_required' => ['sometimes','boolean'], 'context.transit_required' => ['sometimes','boolean'],
            'context.route' => ['nullable', 'string', 'max:255'], 'context.goods' => ['nullable', 'string', 'max:255'],
            'context.incoterms' => ['nullable', 'string', 'max:20'],
            'context.inspection_required' => ['sometimes', 'boolean'], 'context.dangerous_goods' => ['sometimes', 'boolean'],
            'context.temperature_controlled' => ['sometimes', 'boolean'],
        ]);
        // Nested objects are explicitly projected: callers cannot forge completion identities or dependencies.
        $allowed = ['key','status','responsible_user_id','due_date','next_action','exception_reason','action_value','document_ids'];
        $data['items'] = array_map(fn ($item) => array_intersect_key($item, array_flip($allowed)), $data['items'] ?? []);
        foreach ($data['items'] as $item) {
            if (($item['status'] ?? '') === 'not_applicable') $this->company($r, 'setup');
        }
        return $this->response($this->ops->update($company, (int) $r->route('order'), $r->user()->id, $data));
    }

    public function templates(Request $r)
    {
        $company = $this->company($r, $r->isMethod('post') ? 'setup' : 'ops');
        if ($r->isMethod('get')) return $this->response(DB::table('ops_checklist_versions')->where('company_id', $company)->latest('version')->get()->map(function ($v) { $v->items = json_decode($v->items, true); return $v; }));
        $data = $r->validate(['transport_type'=>['nullable',Rule::in(\App\Services\Ops\TransportFlow::MODES)],'variant'=>['nullable',Rule::in(['FTL','LTL','FCL','LCL'])], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.key' => ['required', 'regex:/^[a-z0-9_]+$/', 'max:100', 'distinct'], 'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.phase'=>['nullable','string','max:60'], 'items.*.required_documents'=>['sometimes','array','max:20'], 'items.*.required_documents.*'=>['string','max:150'],
            'items.*.depends_on' => ['sometimes', 'array'], 'items.*.depends_on.*' => ['string', 'max:100'],
            'items.*.applies_when' => ['sometimes', 'array'], 'items.*.applies_when.*' => [Rule::in(['inspection_required','dangerous_goods','temperature_controlled','export_required','import_required','transit_required'])]]);
        $items = array_map(fn ($i) => array_intersect_key($i, array_flip(['key','label','depends_on','applies_when','phase','required_documents'])) + ['status' => 'pending'], $data['items']);
        $keys = array_column($items, 'key');
        foreach ($items as $index => $item) {
            // Ordered dependencies make cycles impossible and make completion order unambiguous.
            abort_if(array_diff($item['depends_on'] ?? [], array_slice($keys, 0, $index)) !== [], 422, 'Zavisnost mora biti ranija stavka šablona.');
        }
        app(\App\Services\Ops\TransportFlow::class)->validateVariant($data['transport_type'] ?? '',$data['variant'] ?? null);
        return DB::transaction(function () use ($company, $items, $r, $data) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first() ?? abort(404);
            $version = (int) DB::table('ops_checklist_versions')->where('company_id', $company)->max('version') + 1;
            $id = DB::table('ops_checklist_versions')->insertGetId(['company_id' => $company, 'version' => $version, 'items' => json_encode($items), ... (\Illuminate\Support\Facades\Schema::hasColumn('ops_checklist_versions','transport_type') ? ['transport_type'=>$data['transport_type'] ?? null,'variant'=>$data['variant'] ?? null] : []), 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ops->audit($company, 'template', $id, $r->user()->id, 'published', null, ['version' => $version, 'items' => $items]);
            return $this->response(['id' => $id, 'version' => $version], 201);
        });
    }

    private function decodeCase(object $case): object
    {
        $case->document_ids = json_decode($case->document_ids, true);
        $case->item_outcomes = json_decode($case->item_outcomes ?? '[]', true);
        $hashes = $this->ops->documentHashes($case->document_ids);
        $case->evidence_integrity = $hashes === json_decode($case->document_hashes ?? '{}', true)
            && count($hashes) === count($case->document_ids);
        return $case;
    }

    public function inspection(Request $r)
    {
        $company = $this->company($r);
        $data = $r->validate(['id' => ['nullable', 'integer'], 'revision' => ['required', 'integer', 'min:0'],
            'local_key' => ['required', 'string', 'max:80'], 'control_type' => ['required', Rule::in(['health','phyto','quality'])],
            'status' => ['required', Rule::in(['draft','submitted','received','supplement_requested','laboratory','approved','partial','rejected','archived'])],
            'external_reference' => ['nullable', 'string', 'max:120'], 'authority' => ['nullable', 'string', 'max:255'],
            'responsible_user_id' => ['nullable', 'integer'], 'next_action' => ['nullable', 'string', 'max:1000'],
            'due_at' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:5000'],
            'document_ids' => ['present', 'array', 'max:30'], 'document_ids.*' => ['integer', 'distinct'],
            'item_outcomes' => ['sometimes', 'array', 'max:200'], 'item_outcomes.*.item' => ['required', 'string', 'max:120'],
            'item_outcomes.*.outcome' => ['required', Rule::in(['approved','rejected','pending'])]]);
        $data['item_outcomes'] = array_map(fn ($i) => array_intersect_key($i, array_flip(['item','outcome'])), $data['item_outcomes'] ?? []);
        return DB::transaction(function () use ($company, $data, $r) {
            $order = $this->ops->order($company, (int) $r->route('order'), true);
            abort_if(in_array($order->status, ['closed','cancelled'], true), 422, 'Nalog je zatvoren ili otkazan.');
            $this->ops->member($company, $data['responsible_user_id'] ?? null);
            $before = isset($data['id']) ? DB::table('ops_inspection_cases')->where('company_id', $company)->where('order_id', $order->id)->where('id', $data['id'])->first() ?? abort(404) : null;
            if (!app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(), $company)) {
                abort_unless($before && (int) $before->responsible_user_id === (int) $r->user()->id && (int) ($data['responsible_user_id'] ?? 0) === (int) $before->responsible_user_id, 403);
            }
            abort_unless($data['revision'] === (int) ($before?->revision ?? 0), 409, 'Predmet je izmijenjen. Osvježite prikaz.');
            $documents = DB::table('ops_order_documents')->where('order_id', $order->id)->whereIn('document_id', $data['document_ids'])->pluck('document_id')->all();
            abort_if(array_diff($data['document_ids'], $documents) !== [], 422, 'Dokaz mora biti dokument ovog naloga.');
            if ($data['status'] !== 'draft') abort_if(empty($data['external_reference']) || empty($data['authority']) || $documents === [], 422, 'Za službeni događaj unesite vanjsku referencu, nadležni organ i dokaz.');
            $hashes = $this->ops->documentHashes($data['document_ids']);
            if ($data['status'] !== 'draft') abort_if(count($hashes) !== count($data['document_ids']), 422, 'Dokazne datoteke moraju biti dostupne.');
            if ($data['status'] === 'partial') abort_if($data['item_outcomes'] === [], 422, 'Djelimična odluka traži ishode po stavkama.');
            $duplicate = DB::table('ops_inspection_cases')->where('company_id', $company)->where('order_id', $order->id)->where('local_key', $data['local_key'])->when($before, fn ($q) => $q->where('id', '!=', $before->id))->exists();
            abort_if($duplicate, 409, 'Predmet s ovim lokalnim ključem već postoji.');
            $values = array_diff_key($data, array_flip(['id','revision']));
            $values['due_at'] = empty($data['due_at']) ? null : \Carbon\Carbon::parse($data['due_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $values['document_ids'] = json_encode($data['document_ids']); $values['item_outcomes'] = json_encode($data['item_outcomes']);
            $values['document_hashes'] = json_encode($hashes);
            $values += ['company_id' => $company, 'order_id' => $order->id, 'revision' => ($before?->revision ?? 0) + 1, 'recorded_by' => $r->user()->id, 'updated_at' => now()];
            if ($before) { DB::table('ops_inspection_cases')->where('id', $before->id)->update($values); $id = $before->id; }
            else $id = DB::table('ops_inspection_cases')->insertGetId($values + ['created_at' => now()]);
            $this->ops->audit($company, 'inspection', $id, $r->user()->id, 'manually_recorded', $before, $values);
            return $this->response($this->decodeCase(DB::table('ops_inspection_cases')->find($id)), $before ? 200 : 201);
        });
    }

    public function overview(Request $r)
    {
        $company = $this->company($r); $rows = [];
        foreach (DB::table('ops_orders')->where('company_id',$company)->whereNotIn('status',['closed','cancelled'])->orderByDesc('id')->cursor() as $order) {
            if(app(\App\Services\Ops\OpsOrders::class)->intake($order)['kind']==='inquiry')continue;
            $flow = app(\App\Services\Ops\TransportFlow::class)->read($order);
            $payment = app(\App\Services\Ops\TransportSettlement::class)->read($order)['payment'];
            foreach (app(\App\Services\Ops\OpsTaskAccess::class)->project($r->user(), $order, $this->ops->read($order))['items'] as $item) {
                if (($item['key'] ?? '') === 'financial_review') continue;
                if (($item['applicable'] ?? true) === false) continue;
                $rows[] = ['order_id'=>$order->id,'reference'=>$order->reference,'transport_type'=>$flow['transport_type'],'payment'=>$payment,'kind'=>'checklist','item'=>$item];
            }
        }
        return $this->response($rows);
    }

    public function today(Request $r)
    {
        $company = $this->company($r); $result = [];
        foreach (DB::table('ops_orders')->where('company_id', $company)->whereNotIn('status', ['closed','cancelled'])->orderBy('id')->cursor() as $order) {
            if(app(\App\Services\Ops\OpsOrders::class)->intake($order)['kind']==='inquiry')continue;
            foreach (app(\App\Services\Ops\OpsTaskAccess::class)->project($r->user(), $order, $this->ops->read($order))['items'] as $item) {
                if (($item['key'] ?? '') === 'financial_review') continue;
                if (($item['applicable'] ?? true) === false || in_array($item['status'] ?? '', ['completed','not_applicable'], true)) continue;
                if (in_array($item['status'] ?? '', ['blocked','waiting_external']) || (!empty($item['due_date']) && substr($item['due_date'], 0, 10) <= now(config('app.timezone'))->toDateString())) {
                    $result[] = ['order_id' => $order->id, 'reference' => $order->reference, 'kind' => 'checklist', 'item' => $item];
                }
            }
        }
        foreach (DB::table('ops_inspection_cases')->join('ops_orders', 'ops_orders.id', '=', 'ops_inspection_cases.order_id')
            ->where('ops_inspection_cases.company_id', $company)->whereNotIn('ops_orders.status', ['closed','cancelled'])
            ->whereNotIn('ops_inspection_cases.status', ['approved','rejected','archived'])->whereNotNull('due_at')->where('due_at', '<=', now()->endOfDay())->get(['ops_inspection_cases.*', 'ops_orders.reference']) as $case) {
            if (!app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(), $company) && (int) $case->responsible_user_id !== (int) $r->user()->id) continue;
            $result[] = ['order_id' => $case->order_id, 'reference' => $case->reference, 'kind' => 'inspection', 'item' => $this->decodeCase($case)];
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('ops_transport_flows')) {
            foreach (DB::table('ops_transport_flows')->join('ops_orders','ops_orders.id','=','ops_transport_flows.order_id')->where('ops_transport_flows.company_id',$company)->where('ops_orders.status','!=','cancelled')->get(['ops_transport_flows.*','ops_orders.reference']) as $flow) {
                foreach (json_decode($flow->transit,true) as $transit) if (($transit['status'] ?? '') !== 'discharged' && (app(\App\Services\Ops\OpsTaskAccess::class)->manages($r->user(), $company) || (int) ($transit['responsible_user_id'] ?? 0) === (int) $r->user()->id)) $result[]=['order_id'=>$flow->order_id,'reference'=>$flow->reference,'kind'=>'transit','item'=>$transit+['label'=>'Otvoren tranzit / NCTS','next_action'=>'Provjeriti službeni status tranzita']];
            }
        }
        if(\Illuminate\Support\Facades\Schema::hasTable('crm_follow_ups'))foreach(DB::table('crm_follow_ups')->where('company_id',$company)->where('owner_user_id',$r->user()->id)->whereNull('done_at')->whereDate('due_on','<=',now()->toDateString())->get() as $follow){
            $order=DB::table('ops_orders')->where('company_id',$company)->where('crm_document_id',$follow->crm_document_id)->whereNotIn('status',['closed','cancelled'])->first();
            if($order&&app(\App\Services\Ops\OpsOrders::class)->intake($order)['kind']==='order')$result[]=['order_id'=>$order->id,'reference'=>$order->reference,'kind'=>'crm_follow_up','item'=>['key'=>'crm_'.$follow->id,'label'=>$follow->note,'next_action'=>$follow->note,'due_date'=>$follow->due_on,'responsible_user_id'=>$follow->owner_user_id,'source_id'=>$follow->id]];
        }
        return $this->response($result);
    }

    public function linkDocument(Request $r)
    {
        $company = $this->company($r);
        $data = $r->validate(['document_id' => ['required','integer']]);
        return DB::transaction(function () use ($r, $company, $data) {
            $order = $this->ops->order($company, (int) $r->route('order'), true);
            abort_if(in_array($order->status, ['closed','cancelled'], true), 422);
            $document = Document::whereKey($data['document_id'])->where(function($q) use ($r,$order) { $q->where('uploaded_by_user_id',$r->user()->id); if ($order->load_id) $q->orWhere('load_id',$order->load_id); })->first() ?? abort(422, 'Dokument nije dostupan.');
            abort_if($document->load_id && (int) $document->load_id !== (int) $order->load_id, 422, 'Dokument pripada drugom teretu.');
            abort_if($document->vehicle_id || $document->user_id || $document->load_draft_id, 422, 'Lični dokument ili dokument vozila ne pripada inspekcijskom predmetu.');
            if (!DB::table('ops_order_documents')->where('order_id', $order->id)->where('document_id', $document->id)->exists()) {
                DB::table('ops_order_documents')->insert(['order_id' => $order->id, 'role' => 'attachment', 'document_id' => $document->id, 'created_at' => now()]);
                $this->ops->audit($company, 'checklist', $order->id, $r->user()->id, 'document_linked', null, ['document_id' => $document->id]);
            }
            return $this->response(['document_id' => $document->id]);
        });
    }

    public function profile(Request $r)
    {
        [$company, $manage] = $this->profileAccess($r, !$r->isMethod('get'));
        $record=DB::table('companies')->where('id',$company)->first() ?? abort(404);
        $headquarters=strtoupper(trim((string)($record->country_code??'')));
        $countryProfile=DB::table('company_country_profiles')->where('company_id',$company)->first();
        $defaultCountry=in_array($headquarters,['BA','HR','RS'],true)?$headquarters:($countryProfile?->country_code??null);
        if ($r->isMethod('get')) return $this->response(['company'=>['id'=>(int)$record->id,'name'=>$record->name,'country_code'=>$headquarters?:null], 'default_country_code'=>$defaultCountry,'profile' => DB::table('company_country_profiles')->where('company_id', $company)->first(),
            'can_manage' => $manage, 'rules' => config('country_verification'), 'verifications' => DB::table('company_capability_verifications')->where('company_id', $company)->latest('id')->get()->map(function ($v) use ($manage) {
                $v->document_ids = $manage ? json_decode($v->document_ids, true) : [];
                if (!$manage) { $v->reason = null; $v->registry_reference = null; }
                $v->effective_status = $v->status === 'verified' && $v->expires_at && $v->expires_at < now()->toDateString() ? 'expired' : $v->status;
                return $v;
            })]);
        $data = $r->validate(['country_code' => ['required', Rule::in(['BA','HR','RS'])], 'revision' => ['required', 'integer', 'min:0']]);
        return DB::transaction(function () use ($r, $company, $data) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first() ?? abort(404);
            $before = DB::table('company_country_profiles')->where('company_id', $company)->first();
            abort_unless($data['revision'] === (int) ($before?->revision ?? 0), 409, 'Profil je izmijenjen.');
            $pack = config('country_verification.countries.'.$data['country_code']); unset($pack['name']);
            $values = $pack + ['country_code' => $data['country_code'], 'revision' => ($before?->revision ?? 0) + 1, 'updated_at' => now(), 'created_at' => $before?->created_at ?? now()];
            DB::table('company_country_profiles')->updateOrInsert(['company_id' => $company], $values);
            $this->ops->audit($company, 'country_profile', $company, $r->user()->id, 'updated', $before, $values);
            return $this->response($values);
        });
    }

    public function verification(Request $r)
    {
        [$company] = $this->profileAccess($r, true);
        $data = $r->validate(['country_code' => ['required', Rule::in(['BA','HR','RS'])],
            'capability' => ['required', Rule::in(config('country_verification.capabilities'))],
            'activity' => ['required', Rule::in(config('country_verification.activities'))],
            'subject_user_id' => ['nullable','integer'],
            'document_ids' => ['present', 'array', 'max:20'], 'document_ids.*' => ['integer', 'distinct'],
            'registry_reference' => ['nullable', 'url', 'max:2000'], 'expires_at' => ['nullable', 'date', 'after:today']]);
        return DB::transaction(function () use ($r, $company, $data) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first() ?? abort(404);
            abort_if($data['capability'] === 'driver' && empty($data['subject_user_id']), 422, 'Izaberite vozača iz kompanije.');
            abort_if($data['capability'] !== 'driver' && !empty($data['subject_user_id']), 422, 'Ova sposobnost se odnosi na kompaniju.');
            $this->ops->member($company, $data['subject_user_id'] ?? null);
            if (!empty($data['expires_at'])) $data['expires_at'] = \Carbon\Carbon::parse($data['expires_at'])->toDateString();
            foreach ($data['document_ids'] as $id) {
                $link = DB::table('compliance_document_links')->where('document_id', $id)->first();
                if ($link) abort_unless((int) $link->company_id === $company, 422, 'Dokument pripada drugoj kompaniji.');
                else {
                    $document = Document::where('id', $id)->where('uploaded_by_user_id', $r->user()->id)->whereNull('load_id')->whereNull('vehicle_id')->whereNull('load_draft_id')
                        ->whereNotIn('id', DB::table('ops_order_documents')->whereNotNull('document_id')->select('document_id'))->first() ?? abort(422, 'Izaberite vlastiti dokument iz arhive, bez veze s teretom ili vozilom.');
                    $path = 'documents/'.$document->path;
                    abort_unless(Storage::disk('local')->exists($path), 422, 'Datoteka dokumenta nije dostupna.');
                    DB::table('compliance_document_links')->insert(['company_id' => $company, 'document_id' => $id, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'registered_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $data['document_ids'] = json_encode($data['document_ids']);
            $id = DB::table('company_capability_verifications')->insertGetId($data + ['company_id' => $company, 'status' => 'submitted', 'rule_version' => config('country_verification.version'), 'submitted_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ops->audit($company, 'verification', $id, $r->user()->id, 'submitted', null, $data);
            return $this->response(['id' => $id, 'status' => 'submitted'], 201);
        });
    }

    public function review(Request $r)
    {
        abort_unless($r->user()->isSuperAdminOrMaster(), 403, 'Provjeru obavlja administrator platforme.');
        $company = (int) $r->route('company');
        $data = $r->validate(['revision' => ['required','integer','min:1'],
            'status' => ['required', Rule::in(['in_review','supplement_requested','verified','rejected','suspended'])],
            'reason' => ['required','string','max:2000'], 'verification_method' => ['required', Rule::in(['registry','manual_document','combined'])]]);
        return DB::transaction(function () use ($r, $company, $data) {
            $record = DB::table('company_capability_verifications')->where('company_id', $company)->where('id', $r->route('verification'))->lockForUpdate()->first() ?? abort(404);
            abort_if((int) $record->submitted_by === (int) $r->user()->id, 422, 'Podnosilac ne može potvrditi vlastiti zahtjev.');
            abort_unless($data['revision'] === (int) $record->revision, 409, 'Zahtjev je izmijenjen.');
            $transitions = ['submitted' => ['in_review','supplement_requested','rejected'], 'in_review' => ['supplement_requested','verified','rejected'],
                'supplement_requested' => ['in_review','rejected'], 'verified' => ['suspended'], 'suspended' => ['in_review']];
            abort_unless(in_array($data['status'], $transitions[$record->status] ?? [], true), 422, 'Nedozvoljen prelaz statusa.');
            if ($data['status'] === 'verified') {
                abort_if(($record->expires_at && $record->expires_at < now()->toDateString()) || (json_decode($record->document_ids, true) === [] && !$record->registry_reference), 422, 'Provjera traži važeći dokument ili referencu registra.');
                foreach (json_decode($record->document_ids, true) as $documentId) {
                    $link = DB::table('compliance_document_links')->where('company_id', $company)->where('document_id', $documentId)->first() ?? abort(422, 'Dokaz nije povezan s kompanijom.');
                    $document = Document::withoutGlobalScopes()->findOrFail($documentId);
                    $path = 'documents/'.$document->path;
                    abort_unless(Storage::disk('local')->exists($path) && hash_equals($link->sha256, hash_file('sha256', Storage::disk('local')->path($path))), 409, 'Dokaz nije dostupan ili je izmijenjen.');
                }
            }
            $values = $data + ['reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]; $values['revision']++;
            DB::table('company_capability_verifications')->where('id', $record->id)->update($values);
            $this->ops->audit($company, 'verification', $record->id, $r->user()->id, 'reviewed', $record, $values);
            return $this->response($values);
        });
    }

    public function evidence(Request $r)
    {
        [$company] = $this->profileAccess($r, true);
        $link = DB::table('compliance_document_links')->where('company_id', $company)->where('document_id', $r->route('document'))->first() ?? abort(404);
        $document = Document::withoutGlobalScopes()->findOrFail($link->document_id);
        $path = 'documents/'.$document->path;
        abort_unless(Storage::disk('local')->exists($path), 404);
        abort_unless(hash_equals($link->sha256, hash_file('sha256', Storage::disk('local')->path($path))), 409, 'Dokument je izmijenjen; potrebna je ponovna provjera.');
        $this->ops->audit($company, 'document', $document->id, $r->user()->id, 'downloaded', null, null);
        return Storage::disk('local')->download($path, $document->name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
