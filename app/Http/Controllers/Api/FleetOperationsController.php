<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyTeamAccess;
use App\Services\FleetOperations;
use App\Services\FleetTourLoads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FleetOperationsController extends Controller
{
    public function __construct(private FleetOperations $fleet) {}

    private function access(Request $r, int $company, bool $write = true): string
    {
        return $this->fleet->authorize($r->user(),$company,$write);
    }

    private function scoped(Request $r,int $company,string $table,string $kind,string $alias='') {
        return app(\App\Services\FleetDepotAccess::class)->query(DB::table($alias?$table.' as '.$alias:$table)->where(($alias?$alias.'.':'').'company_id',$company),$r->user(),$company,$kind,$alias);
    }
    private function record(Request $r,int $company,string $table,string $kind,int $id) {
        $row=$this->scoped($r,$company,$table,$kind)->where('id',$id)->first();abort_unless($row,404);return $row;
    }
    private function ability(Request $r,int $company,string $ability): array { return app(\App\Services\FleetDepotAccess::class)->authorize($r->user(),$company,$ability); }

    private function ok(mixed $data): \Illuminate\Http\JsonResponse { return response()->json(['data'=>$data]); }

    public function companies(Request $r)
    {
        $q=app(CompanyTeamAccess::class)->companies($r->user());
        if($primary=$r->user()->driver?->primary_company_id)$q->orWhere('id',$primary);
        return $this->ok($q->get(['id','name'])->filter(fn($c)=>in_array($this->fleet->role($r->user(),$c->id),['superadmin','master','company','fleet_manager','depot_manager','dispatcher','driver','workshop'],true))->values());
    }

    public function notifications(Request $r)
    {
        $items=collect();
        foreach(app(CompanyTeamAccess::class)->companies($r->user())->pluck('id') as $company) {
            try { $this->ability($r,$company,'damage'); } catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()===403)continue;throw $e;}
            $ids=$this->scoped($r,$company,'fleet_damages','damage')->select('id');
            $items=$items->concat(DB::table('fleet_notifications as n')->join('fleet_damages as d','d.id','=','n.damage_id')->join('vehicles as v','v.id','=','d.vehicle_id')->whereIn('d.id',$ids)->whereNull('n.read_at')->select('n.id','n.company_id','n.damage_id','d.description','v.registration_number')->limit(50)->get());
        }
        return $this->ok($items->sortByDesc('id')->take(50)->values());
    }

    public function context(Request $r,int $company)
    {
        $scope=$this->ability($r,$company,'view');$role=$scope['role'];
        $vehicles=app(\App\Services\FleetDepotAccess::class)->query(Vehicle::where('company_id',$company),$r->user(),$company,'vehicle')->orderBy('registration_number')->get();
        $members=User::whereHas('companies',fn($q)=>$q->where('companies.id',$company)->where('company_user.status','active'))->orWhereHas('driver',fn($q)=>$q->where('primary_company_id',$company))->get(['id','name']);
        $drivers=$members->filter(fn($u)=>app(CompanyTeamAccess::class)->role($u,$company)==='driver'||(int)$u->driver?->primary_company_id===$company)->map(fn($u)=>['id'=>$u->id,'name'=>$u->name])->values();
        $driverIds=app(\App\Services\FleetDepotAccess::class)->driverIds($r->user(),$company,$vehicles->pluck('assigned_driver_user_id')->all());
        if($driverIds!==null)$drivers=$drivers->filter(fn($d)=>in_array($d['id'],$driverIds))->values();
        $depots=DB::table('fleet_depots')->where('company_id',$company)->when(!$scope['all'],fn($q)=>$q->whereIn('id',$role==='driver'?$vehicles->pluck('depot_id')->filter()->all():$scope['depots']))->orderBy('name')->get();
        $notifications=in_array('damage',$scope['abilities'])?$this->scoped($r,$company,'fleet_damages','damage','d')->join('fleet_notifications as n','n.damage_id','=','d.id')->join('vehicles as v','v.id','=','d.vehicle_id')->whereNull('n.read_at')->select('n.id','n.damage_id','d.description','v.registration_number')->get():collect();
        return $this->ok(['can_manage'=>in_array('dispatch',$scope['abilities']),'abilities'=>$scope['abilities'],'role'=>$role,'all_depots'=>$scope['all'],
            'vehicles'=>$vehicles,'drivers'=>$role==='workshop'?[]:$drivers,'depots'=>$depots->pluck('name'),'depot_records'=>$depots,'notifications'=>$notifications,'unread_count'=>$notifications->count()]);
    }

    public function handovers(Request $r, int $company)
    {
        $role=$this->access($r,$company,false);
        $q=$this->scoped($r,$company,'fleet_handovers','handover','h')->leftJoin('users as f','f.id','=','h.from_driver_id')->leftJoin('users as t','t.id','=','h.to_driver_id')->leftJoin('users as a','a.id','=','h.recorded_by')->leftJoin('users as reviewer','reviewer.id','=','h.reviewed_by')->where('h.company_id',$company);
        if($role==='driver')$q->where(fn($x)=>$x->where('h.from_driver_id',$r->user()->id)->orWhere('h.to_driver_id',$r->user()->id));
        foreach(['vehicle_id','review_status','depot_name'] as $key)if($r->filled($key))$q->where('h.'.$key,$r->input($key));
        if($r->filled('depot_id')){$r->input('depot_id')==='unassigned'?$q->whereNull('h.depot_id'):$q->where('h.depot_id',$r->integer('depot_id'));}
        if($r->filled('from'))$q->whereDate('h.effective_at','>=',$r->input('from'));
        if($r->filled('to'))$q->whereDate('h.effective_at','<=',$r->input('to'));
        $page=$q->select('h.*','f.name as from_driver','t.name as to_driver','a.name as author','reviewer.name as reviewer')->orderByDesc('h.effective_at')->orderByDesc('h.id')->paginate($r->boolean('with_photos')?20:50);
        if($r->boolean('with_photos'))$page->getCollection()->transform(function($h)use($company){
            $h->photos=DB::table('fleet_files')->where('handover_id',$h->id)->get(['id','name','mime_type','size_bytes'])->map(function($f)use($company){$f->fleet_company_id=$company;return $f;});
            if($h->inspection_id)$h->photos=$h->photos->concat(DB::table('vehicle_return_photos')->where('vehicle_return_inspection_id',$h->inspection_id)->get(['id','name','mime_type','size_bytes']));
            return $h;
        });
        return $this->ok($page);
    }

    private function handoverRow(int $company,int $id): object
    {
        return DB::table('fleet_handovers')->where('company_id',$company)->where('id',$id)->firstOrFail();
    }

    public function handoverDetail(Request $r,int $company,int $id)
    {
        $role=$this->access($r,$company,false);$h=$this->record($r,$company,'fleet_handovers','handover',$id);
        abort_if($role==='driver'&&!in_array((int)$r->user()->id,[(int)$h->from_driver_id,(int)$h->to_driver_id],true),403);
        $previous=$this->scoped($r,$company,'fleet_handovers','handover')->where('vehicle_id',$h->vehicle_id)->where('id','<',$h->id)->orderByDesc('id')->first();
        $files=fn($row)=>!$row?[]:DB::table('fleet_files')->where('handover_id',$row->id)->get(['id','name','mime_type']);
        $legacy=fn($row)=>!$row?->inspection_id?[]:DB::table('vehicle_return_photos')->where('vehicle_return_inspection_id',$row->inspection_id)->get(['id','name','mime_type']);
        return $this->ok(['handover'=>$h,'files'=>$files($h),'legacy_photos'=>$legacy($h),'previous'=>$previous,'previous_files'=>$files($previous),'previous_legacy_photos'=>$legacy($previous),
            'damages'=>$role==='driver'?[]:$this->scoped($r,$company,'fleet_damages','damage')->where('vehicle_id',$h->vehicle_id)->orderByDesc('id')->get()]);
    }

    public function createHandover(Request $r,int $company)
    {
        $role=$this->access($r,$company,false);$this->ability($r,$company,'handover');
        $d=$r->validate(['vehicle_id'=>['required','integer'],'destination'=>['required','in:driver,depot,workshop,returned'],
            'request_key'=>['sometimes','uuid'],
            'to_driver_id'=>['nullable','required_if:destination,driver','integer'],'depot_name'=>['nullable','string','max:120'],'depot_id'=>['nullable','integer','required_if:destination,depot'],
            'mileage_km'=>['required','integer','min:0','max:999999999'],'fuel_level_percent'=>['required','integer','between:0,100'],
            'has_damage'=>['required','boolean'],'notes'=>['nullable','required_if:has_damage,1,true','string','max:5000'],
            'photos'=>['required','array','min:3','max:10'],'photos.*'=>['file','mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif','max:12288']]);
        $key=$d['request_key']??null;unset($d['request_key']);
        if($key)abort_unless(\Illuminate\Support\Facades\Schema::hasTable('fleet_handover_requests'),503,'Handover release is being prepared. Retry shortly.');
        $payload=[];foreach(['vehicle_id','to_driver_id','depot_id','mileage_km','fuel_level_percent'] as $field)$payload[$field]=isset($d[$field])&&$d[$field]!==''?(int)$d[$field]:null;
        foreach(['destination','depot_name','notes'] as $field)$payload[$field]=(string)($d[$field]??'');$payload['has_damage']=(bool)$d['has_damage'];
        $payload['photos']=array_map(fn($file)=>hash_file('sha256',$file->getRealPath()),$r->file('photos',[]));
        $fingerprint=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));$paths=[];
        try {
            $id=DB::transaction(function()use($r,$company,$role,$d,$key,$fingerprint,&$paths){
                // Serialize dispatch changes company-wide, including a driver moving between vehicles.
                DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
                if($key){$prior=DB::table('fleet_handover_requests')->where(['company_id'=>$company,'actor_id'=>$r->user()->id,'request_key'=>$key])->first();if($prior){abort_unless(hash_equals($prior->fingerprint,$fingerprint),409,'This request key was already used for different handover data. Refresh the handover history.');return $prior->handover_id;}}
                $v=Vehicle::where('company_id',$company)->whereKey($d['vehicle_id'])->lockForUpdate()->firstOrFail();
                abort_if($role==='driver'&&(int)$v->assigned_driver_user_id!==(int)$r->user()->id,403);
                abort_if($role==='driver'&&$d['destination']==='returned',403);
                $id=$this->fleet->handover($v,$r->user()->id,$d);
                foreach($r->file('photos',[]) as $file)$this->storeFile($file,$company,$id,null,$paths);
                if($key)DB::table('fleet_handover_requests')->insert(['company_id'=>$company,'actor_id'=>$r->user()->id,'request_key'=>$key,'fingerprint'=>$fingerprint,'handover_id'=>$id,'created_at'=>now(),'updated_at'=>now()]);
                return $id;
            });
        }catch(\Throwable $e){foreach($paths as $p)Storage::disk('local')->delete($p);throw $e;}
        return $this->ok(['id'=>$id]);
    }

    public function review(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'review');$this->record($r,$company,'fleet_handovers','handover',$id);
        $this->access($r,$company);
        $d=$r->validate(['decision'=>['required','in:clear,damage'],'description'=>['nullable','required_if:decision,damage','string','max:10000'],
            'kind'=>['sometimes','in:internal,accident'],'block_vehicle'=>['sometimes','boolean'],'existing_damage_id'=>['nullable','integer']]);
        return DB::transaction(function()use($r,$company,$id,$d){
            $h=DB::table('fleet_handovers')->where('company_id',$company)->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($h->review_status==='pending',409,'Handover already reviewed.');
            $existing=!empty($d['existing_damage_id'])?$this->scoped($r,$company,'fleet_damages','damage')->where('vehicle_id',$h->vehicle_id)->where('id',$d['existing_damage_id'])->first():null;
            abort_if(!empty($d['existing_damage_id'])&&!$existing,422,'Damage case must belong to this vehicle.');
            abort_if($h->has_damage&&$d['decision']==='clear'&&!$existing,422,'Reported damage must be recorded or linked to an existing case.');
            $damage=$existing?->id??($d['decision']==='damage'?$this->fleet->damage($company,$r->user()->id,$h->vehicle_id,$h->id,$d):null);
            DB::table('fleet_handovers')->where('id',$id)->update(['review_status'=>'confirmed','damage_id'=>$damage,'reviewed_by'=>$r->user()->id,'reviewed_at'=>now(),'updated_at'=>now()]);
            $this->fleet->event($company,$r->user()->id,'handover',$id,'reviewed',['decision'=>$d['decision'],'damage_id'=>$damage]);
            return $this->ok(['damage_id'=>$damage]);
        });
    }

    public function damages(Request $r,int $company)
    {
        $this->access($r,$company);
        $q=$this->scoped($r,$company,'fleet_damages','damage','d')->join('vehicles as v','v.id','=','d.vehicle_id')->where('d.company_id',$company);
        if($r->filled('vehicle_id'))$q->where('d.vehicle_id',$r->input('vehicle_id'));
        if($r->filled('status'))$q->where('d.status',$r->input('status'));
        if($r->filled('depot_id')){$r->input('depot_id')==='unassigned'?$q->whereNull('d.depot_id'):$q->where('d.depot_id',$r->integer('depot_id'));}
        return $this->ok($q->select('d.*','v.registration_number')->orderByDesc('d.id')->paginate(50));
    }

    public function damageDetail(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'damage');$this->record($r,$company,'fleet_damages','damage',$id);
        $this->access($r,$company);
        $d=DB::table('fleet_damages')->where('company_id',$company)->where('id',$id)->firstOrFail();
        $d->details=json_decode($d->details??'{}',true);
        return $this->ok(['damage'=>$d,'files'=>DB::table('fleet_files')->where('damage_id',$id)->get(['id','name','mime_type']),
            'events'=>DB::table('fleet_events as e')->leftJoin('users as u','u.id','=','e.actor_id')->where('e.company_id',$company)->where('entity_type','damage')->where('entity_id',$id)->select('e.*','u.name as actor_name')->orderByDesc('e.id')->get(),
            'handover_files'=>$d->handover_id?DB::table('fleet_files')->where('handover_id',$d->handover_id)->get(['id','name','mime_type']):[],
            'legacy_photos'=>$d->handover_id?DB::table('vehicle_return_photos')->where('vehicle_return_inspection_id',DB::table('fleet_handovers')->where('id',$d->handover_id)->value('inspection_id'))->get(['id','name','mime_type']):[]]);
    }

    public function updateDamage(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'damage');$this->record($r,$company,'fleet_damages','damage',$id);
        $this->access($r,$company);
        $d=$r->validate(['status'=>['required','in:reported,in_progress,closed'],'kind'=>['required','in:internal,accident'],
            'description'=>['required','string','max:10000'],'details'=>['nullable','array'],
            'details.police_case'=>['nullable','string','max:255'],'details.insurance_case'=>['nullable','string','max:255'],
            'details.driver_statement'=>['nullable','string','max:10000'],'details.resolution'=>['nullable','string','max:10000'],
            'details.participants'=>['nullable','string','max:10000'],'details.contacts'=>['nullable','string','max:10000'],
            'details.third_party_property'=>['nullable','string','max:5000'],'details.consequences'=>['nullable','string','max:5000'],
            'details.cost'=>['nullable','numeric','min:0'],'details.currency'=>['nullable','in:EUR,BAM,USD,GBP'],
            'block_vehicle'=>['sometimes','boolean']]);
        if($d['status']==='closed'&&empty($d['details']['resolution']))abort(422,'Enter the resolution before closing the case.');
        return DB::transaction(function()use($r,$company,$id,$d){
            $row=DB::table('fleet_damages')->where('company_id',$company)->where('id',$id)->lockForUpdate()->firstOrFail();
            DB::table('fleet_damages')->where('id',$id)->update(['kind'=>$d['kind'],'status'=>$d['status'],'description'=>$d['description'],
                'details'=>json_encode($d['details']??[]),'closed_at'=>$d['status']==='closed'?now():null,'updated_at'=>now()]);
            // Closing a case does not automatically clear a technical dispatch block.
            if(!empty($d['block_vehicle']))Vehicle::whereKey($row->vehicle_id)->update(['dispatch_blocked'=>true]);
            $this->fleet->event($company,$r->user()->id,'damage',$id,'updated',['from_status'=>$row->status,'to_status'=>$d['status'],'details'=>$d['details']??[]]);
            return $this->ok(['id'=>$id]);
        });
    }

    private function storeFile($file,int $company,?int $handover,?int $damage,array &$paths): void
    {
        $path=$file->store('fleet/'.$company,'local');$paths[]=$path;
        DB::table('fleet_files')->insert(['company_id'=>$company,'handover_id'=>$handover,'damage_id'=>$damage,
            'name'=>$file->getClientOriginalName(),'path'=>$path,'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'created_at'=>now(),'updated_at'=>now()]);
    }

    public function upload(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'damage');$this->record($r,$company,'fleet_damages','damage',$id);
        $this->access($r,$company);DB::table('fleet_damages')->where('company_id',$company)->where('id',$id)->firstOrFail();
        $r->validate(['files'=>['required','array','min:1','max:20'],'files.*'=>['required','file','mimes:pdf,jpg,jpeg,png,webp,heic,heif,doc,docx,xls,xlsx,txt','max:20480']]);
        $paths=[];try{DB::transaction(function()use($r,$company,$id,&$paths){foreach($r->file('files') as $f)$this->storeFile($f,$company,null,$id,$paths);$this->fleet->event($company,$r->user()->id,'damage',$id,'files_added');});}
        catch(\Throwable $e){foreach($paths as $p)Storage::disk('local')->delete($p);throw $e;}
        return $this->ok(['uploaded'=>count($paths)]);
    }

    public function file(Request $r,int $company,int $id)
    {
        $role=$this->access($r,$company,false);$f=DB::table('fleet_files')->where('company_id',$company)->where('id',$id)->firstOrFail();
        if($f->damage_id){$this->ability($r,$company,'damage');$this->record($r,$company,'fleet_damages','damage',$f->damage_id);}elseif($f->handover_id){$this->record($r,$company,'fleet_handovers','handover',$f->handover_id);}else abort(403);
        if($role==='driver'){$h=$f->handover_id?$this->handoverRow($company,$f->handover_id):null;abort_unless($h&&in_array((int)$r->user()->id,[(int)$h->from_driver_id,(int)$h->to_driver_id],true),403);}
        abort_unless(Storage::disk('local')->exists($f->path),404);
        return Storage::disk('local')->download($f->path,$f->name,['Content-Type'=>$f->mime_type,'X-Content-Type-Options'=>'nosniff']);
    }

    public function vehicle(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'status');$this->record($r,$company,'vehicles','vehicle',$id);$d=$r->validate(['status'=>['required',Rule::in(FleetOperations::STATUSES)],'depot_name'=>['nullable','string','max:120'],'dispatch_blocked'=>['required','boolean'],'reason'=>['required','string','max:2000']]);
        abort_if($d['status']==='returned',422,'Use a return handover for rented vehicles.');
        return DB::transaction(function()use($r,$company,$id,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();
            if(in_array($d['status'],['sold','stolen','maintenance'],true)||$d['dispatch_blocked'])\App\Services\VehicleCouplings::assertDetached($v);
            abort_if(in_array($v->status,['sold','returned'],true),422,'Archived vehicle cannot be reactivated here.');
            $scope=$this->ability($r,$company,'status');
            if($scope['role']==='workshop') {
                abort_unless(in_array($v->status,['maintenance','depot'],true)&&in_array($d['status'],['maintenance','depot'],true)&&!$v->assigned_driver_user_id,403,'Workshop may only update parked repair vehicles.');
                abort_if((bool)$d['dispatch_blocked']!==(bool)$v->dispatch_blocked,403,'Workshop cannot change a dispatch block.');
            }
            abort_if(isset($d['depot_name'])&&$d['depot_name']!==$v->depot_name,422,'Use depot assignment or a handover to change the depot.');
            $before=$v->only(['status','depot_name','dispatch_blocked']);
            if(in_array($d['status'],['sold','stolen'],true)){
                DB::table('fleet_assignments')->where('vehicle_id',$id)->whereNull('ended_at')->update(['ended_at'=>now(),'updated_at'=>now()]);$v->assigned_driver_user_id=null;
            }
            $v->fill(collect($d)->except('reason')->all())->save();
            $this->fleet->event($company,$r->user()->id,'vehicle',$id,'status_changed',['before'=>$before,'after'=>$d]);return $this->ok($v);
        });
    }

    public function tourList(Request $r,int $company)
    {
        $role=$this->access($r,$company,false);
        $d=$r->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'page'=>['nullable','integer','min:1']]);
        $q=$this->scoped($r,$company,'fleet_tours','tour','t')->leftJoin('users as d','d.id','=','t.driver_id')->leftJoin('users as a','a.id','=','t.locked_by')->leftJoin('vehicles as v','v.id','=','t.vehicle_id')->where('t.company_id',$company);
        if($role==='driver')$q->where('t.driver_id',$r->user()->id);
        $q->whereBetween('service_date',[$d['from']??now()->toDateString(),$d['to']??($d['from']??now()->toDateString())]);
        if($r->filled('depot_id'))$q->where('t.depot_id',$r->integer('depot_id'));
        foreach(['depot_name','partner','status'] as $key)if($r->filled($key))$q->where('t.'.$key,$r->input($key));
        if($r->filled('search'))$q->where('tour_code','like','%'.mb_substr($r->input('search'),0,120).'%');
        $page = $q->select('t.*','d.name as driver_name','a.name as locked_by_name','v.registration_number as selected_plate')
            ->orderByDesc('service_date')->orderBy('depot_name')->orderBy('partner')->orderBy('tour_code')->paginate(100);
        if ($r->boolean('with_loads')) {
            $loads = app(FleetTourLoads::class)->forTours($company, $page->getCollection()->pluck('id')->all());
            $page->through(function ($tour) use ($loads) {
                $tour->loads = $loads->get($tour->id, collect());
                return $tour;
            });
        }
        return $this->ok($page);
    }

    public function importTours(Request $r,int $company)
    {
        $this->access($r,$company);
        $d=$r->validate(['service_date'=>['required','date_format:Y-m-d'],'depot_name'=>['nullable','string','max:120'],'depot_id'=>['required','integer'],
            'partner'=>['required','string','max:80'],'rows'=>['required','array','min:1','max:3000'],'rows.*.tour_code'=>['nullable','string','max:120'],
            'rows.*.driver_name'=>['nullable','string','max:255'],'confirm'=>['required','boolean'],
            'source'=>['nullable',Rule::in(['amazon'])],
            'rows.*.source_data'=>['nullable','array:dsp,transporter_id,progress,service_type,duration,activities,completed_stops,remaining_stops,deliveries,pickups,roadside_pickups,time_window,departure,service_stops,bulk_weight'],
            'rows.*.source_data.*'=>['nullable','string','max:120']]);
        $depot=app(\App\Services\FleetDepotAccess::class)->depot($r->user(),$company,$d['depot_id']);$d['depot_name']=$depot->name;$d['partner']=trim($d['partner']);
        if (($d['source'] ?? null) === 'amazon') {
            $d['partner'] = 'Amazon';
        }
        abort_if($d['depot_name']===''||$d['partner']==='',422,'Depot and partner are required.');
        $seen=[];$valid=[];$invalid=[];$duplicates=[];
        foreach($d['rows'] as $i=>$row){
            $code=trim($row['tour_code']??'');
            if($code===''){$invalid[]=['row'=>$i+1,'reason'=>'Missing tour ID'];continue;}
            $key=mb_strtolower($code);
            if(isset($seen[$key])||DB::table('fleet_tours')->where('company_id',$company)->where('service_date',$d['service_date'])->where('depot_id',$d['depot_id'])->where('partner',$d['partner'])->whereRaw('LOWER(tour_code) = ?',[$key])->exists()){$duplicates[]=['row'=>$i+1,'tour_code'=>$code];continue;}
            $seen[$key]=true;$valid[]=['tour_code'=>$code,'driver_name'=>$row['driver_name']??null,
                'source_data'=>($d['source']??null)==='amazon'?($row['source_data']??null):null];
        }
        $count=0;
        if($d['confirm']){
            $count=DB::transaction(function()use($r,$company,$d,$valid){
                DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();$count=0;
                foreach($valid as $row){$identity=['company_id'=>$company,'service_date'=>$d['service_date'],'depot_id'=>$d['depot_id'],'partner'=>trim($d['partner']),'tour_code'=>$row['tour_code']];
                    if(DB::table('fleet_tours')->where(collect($identity)->except('tour_code')->all())->whereRaw('LOWER(tour_code) = ?',[mb_strtolower($row['tour_code'])])->exists())continue;
                    $record = $identity+['depot_name'=>$d['depot_name'],'source_driver_name'=>$row['driver_name'],
                        'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()];
                    if (isset($d['source'])) {
                        $record += ['import_source'=>$d['source'],'source_data'=>json_encode($row['source_data'], JSON_THROW_ON_ERROR)];
                    }
                    DB::table('fleet_tours')->insert($record);$count++;
                }
                $this->fleet->event($company,$r->user()->id,'import',0,'tours_imported',['count'=>$count,'service_date'=>$d['service_date'],'partner'=>$d['partner']]);return $count;
            });
        }
        return $this->ok(['valid'=>$valid,'invalid'=>$invalid,'duplicates'=>$duplicates,'created'=>$count]);
    }

    public function mapping(Request $r,int $company)
    {
        $this->access($r,$company);
        $d=$r->validate(['partner'=>['required','string','max:80'],'headers'=>['required','array','min:1','max:100'],
            'headers.*'=>['nullable','string','max:255'],'sample'=>['sometimes','array','max:5'],'sample.*'=>['array','max:100'],
            'sample.*.*'=>['nullable','string','max:255'],'recognize'=>['sometimes','boolean'],
            'mapping'=>['sometimes','array:id_column,driver_column,date_column'],
            'mapping.id_column'=>['nullable','integer','min:0'],'mapping.driver_column'=>['nullable','integer','min:0'],'mapping.date_column'=>['nullable','integer','min:0']]);
        $hash=hash('sha256',json_encode($d['headers']));$key=['company_id'=>$company,'partner'=>trim($d['partner']),'header_hash'=>$hash];
        if(isset($d['mapping'])){
            foreach($d['mapping'] as $index)abort_if($index!==null&&$index>=count($d['headers']),422,'Mapping index is out of range.');
            DB::table('fleet_import_profiles')->updateOrInsert($key,['mapping'=>json_encode($d['mapping']),'updated_at'=>now(),'created_at'=>now()]);
            return $this->ok($d['mapping']);
        }
        $saved=DB::table('fleet_import_profiles')->where($key)->first();
        if($saved)return $this->ok(json_decode($saved->mapping,true));
        return $this->ok(!empty($d['recognize'])?app(\App\Services\FleetImportMapping::class)->suggest($d['headers'],$d['sample']??[]):null);
    }

    public function tourAction(Request $r,int $company,int $id,string $action)
    {
        $this->ability($r,$company,'dispatch');$this->record($r,$company,'fleet_tours','tour',$id);
        $this->access($r,$company);
        $d=$r->validate(['driver_id'=>['nullable','integer'],'vehicle_id'=>['nullable','integer'],'reason'=>['nullable','string','max:2000'],
            'load_ids'=>['sometimes','array','max:100'],'load_ids.*'=>['integer','distinct','min:1'],
            'partner_url'=>['nullable','url:http,https','max:2000']]);
        return DB::transaction(function()use($r,$company,$id,$action,$d){
            DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
            $tour=DB::table('fleet_tours')->where('company_id',$company)->where('id',$id)->lockForUpdate()->firstOrFail();
            if($action==='assign'){
                $this->assignTour($r, $tour, $d);
            }elseif($action==='loads'){
                abort_unless(array_key_exists('load_ids', $d), 422, 'Provide the ordered load IDs.');
                app(FleetTourLoads::class)->replace($company, $id, $d['load_ids']);
                $this->fleet->event($company, $r->user()->id, 'tour', $id, 'tour_loads_changed', ['load_ids'=>$d['load_ids']]);
            }elseif($action==='lock')$this->fleet->lockTour($tour,$r->user()->id);
            elseif($action==='reopen'){
                abort_unless(!empty($d['reason'])&&$tour->status!=='open',422,'A reason is required to reopen a locked tour.');
                $this->fleet->event($company,$r->user()->id,'tour',$id,'reopened',['reason'=>$d['reason'],'previous'=>(array)$tour]);
                DB::table('fleet_tours')->where('id',$id)->update(['status'=>'open','locked_at'=>null,'locked_by'=>null,'assignment_id'=>null,'plate'=>null,'completed_at'=>null,'updated_at'=>now()]);
            }elseif($action==='complete'){
                abort_unless($tour->status==='locked',422,'Lock the tour before completing it.');
                $assignment=DB::table('fleet_assignments')->find($tour->assignment_id);
                abort_unless($assignment&&(int)$assignment->vehicle_id===(int)$tour->vehicle_id&&(int)$assignment->driver_id===(int)$tour->driver_id,422,'Driver and vehicle do not match the locked assignment.');
                $v=Vehicle::whereKey($tour->vehicle_id)->lockForUpdate()->firstOrFail();
                abort_unless($this->fleet->assignmentEvidence($assignment,$v)===$tour->plate,422,'Driver / plates differ from the locked tour. Reopen the tour with a reason to correct the assignment.');
                DB::table('fleet_tours')->where('id',$id)->update(['status'=>'completed','completed_at'=>now(),'updated_at'=>now()]);
                $this->fleet->event($company,$r->user()->id,'tour',$id,'completed');
            }else abort(404);
            return $this->ok(['id'=>$id]);
        });
    }

    private function assignTour(Request $request, object $tour, array $data): void
    {
        $company = (int) $tour->company_id;
        $changes = array_intersect_key($data, array_flip(['driver_id', 'vehicle_id', 'partner_url']));
        $driverChanged = array_key_exists('driver_id', $changes) && (int) $changes['driver_id'] !== (int) $tour->driver_id;
        $vehicleChanged = array_key_exists('vehicle_id', $changes) && (int) $changes['vehicle_id'] !== (int) $tour->vehicle_id;
        if ($driverChanged && !empty($changes['driver_id'])) {
            $this->fleet->driver($company, $changes['driver_id']);
        }
        if ($vehicleChanged && !empty($changes['vehicle_id'])) {
            $vehicle = Vehicle::where('company_id', $company)->findOrFail($changes['vehicle_id']);
            app(\App\Services\FleetDepotAccess::class)->vehicle($request->user(), $vehicle, 'dispatch');
            abort_unless((int) $vehicle->depot_id === (int) $tour->depot_id, 422, 'Vehicle and tour must belong to the same depot.');
            $this->fleet->assertDispatchable($vehicle->id);
        }
        if ($tour->status !== 'open' && ($driverChanged || $vehicleChanged)) {
            $reason = trim($data['reason'] ?? '');
            abort_if($reason === '', 422, 'Enter a reason to change a confirmed assignment.');
            $this->fleet->event($company, $request->user()->id, 'tour', $tour->id, 'reopened', ['reason'=>$reason, 'previous'=>(array) $tour]);
            $changes += ['status'=>'open','assignment_id'=>null,'plate'=>null,'locked_by'=>null,'locked_at'=>null,'completed_at'=>null];
        }
        DB::table('fleet_tours')->where('id', $tour->id)->update($changes + ['updated_at'=>now()]);
        $this->fleet->event($company, $request->user()->id, 'tour', $tour->id, 'assigned', $data);
    }

    public function tourLoadOptions(Request $request, int $company)
    {
        $this->ability($request, $company, 'dispatch');
        $search = $request->validate(['search'=>['nullable','string','max:120']])['search'] ?? '';
        return $this->ok(app(FleetTourLoads::class)->search($company, trim($search)));
    }

    public function tourDetail(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'view');$this->record($r,$company,'fleet_tours','tour',$id);
        $role=$this->access($r,$company,false);$tour=DB::table('fleet_tours')->where('company_id',$company)->where('id',$id)->firstOrFail();
        abort_if($role==='driver'&&(int)$tour->driver_id!==(int)$r->user()->id,403);
        $tour->source_data = json_decode($tour->source_data ?? 'null', true);
        $loads = app(FleetTourLoads::class)->forTours($company, [$id])->get($id, collect());
        return $this->ok(['tour'=>$tour,'loads'=>$loads,'events'=>DB::table('fleet_events as e')->leftJoin('users as u','u.id','=','e.actor_id')->where('e.company_id',$company)->where('entity_type','tour')->where('entity_id',$id)->select('e.*','u.name as actor_name')->orderByDesc('e.id')->get()]);
    }

    public function readNotification(Request $r,int $company,int $id)
    {
        $this->ability($r,$company,'damage');$n=DB::table('fleet_notifications')->where('company_id',$company)->where('id',$id)->firstOrFail();$this->record($r,$company,'fleet_damages','damage',$n->damage_id);DB::table('fleet_notifications')->where('company_id',$company)->where('id',$id)->update(['read_by'=>$r->user()->id,'read_at'=>now(),'updated_at'=>now()]);return $this->ok(['id'=>$id]);
    }
}
