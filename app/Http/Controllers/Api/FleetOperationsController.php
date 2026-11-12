<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyTeamAccess;
use App\Services\FleetOperations;
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

    private function ok(mixed $data): \Illuminate\Http\JsonResponse { return response()->json(['data'=>$data]); }

    public function companies(Request $r)
    {
        $q=app(CompanyTeamAccess::class)->companies($r->user());
        if($primary=$r->user()->driver?->primary_company_id)$q->orWhere('id',$primary);
        return $this->ok($q->get(['id','name'])->filter(fn($c)=>in_array($this->fleet->role($r->user(),$c->id),['superadmin','master','company','manager','dispatcher','driver'],true))->values());
    }

    public function notifications(Request $r)
    {
        $companies=app(CompanyTeamAccess::class)->companies($r->user())->get(['id'])->filter(fn($c)=>in_array(app(CompanyTeamAccess::class)->role($r->user(),$c->id),['superadmin','master','company','manager','dispatcher'],true))->pluck('id');
        return $this->ok(DB::table('fleet_notifications as n')->join('fleet_damages as d','d.id','=','n.damage_id')->join('vehicles as v','v.id','=','d.vehicle_id')->whereIn('n.company_id',$companies)->whereNull('n.read_at')->select('n.id','n.company_id','n.damage_id','d.description','v.registration_number')->orderByDesc('n.id')->limit(50)->get());
    }

    public function context(Request $r, int $company)
    {
        $role=$this->access($r,$company,false);
        $vehicles=Vehicle::where('company_id',$company)->when($role==='driver',fn($q)=>$q->where('assigned_driver_user_id',$r->user()->id))->orderBy('registration_number')->get(['id','registration_number','assigned_driver_user_id','status','ownership_type','depot_name','dispatch_blocked']);
        $members=User::whereHas('companies',fn($q)=>$q->where('companies.id',$company))->orWhereHas('driver',fn($q)=>$q->where('primary_company_id',$company))->get(['id','name','role_id']);
        $drivers=$members->filter(fn($u)=>app(CompanyTeamAccess::class)->role($u,$company)==='driver'||(int)$u->driver?->primary_company_id===$company)->map(fn($u)=>['id'=>$u->id,'name'=>$u->name])->values();
        return $this->ok(['can_manage'=>$role!=='driver','vehicles'=>$vehicles,'drivers'=>$drivers,
            'depots'=>Vehicle::where('company_id',$company)->whereNotNull('depot_name')->distinct()->pluck('depot_name'),
            'notifications'=>$role==='driver'?[]:DB::table('fleet_notifications')->join('fleet_damages','fleet_damages.id','=','fleet_notifications.damage_id')->join('vehicles','vehicles.id','=','fleet_damages.vehicle_id')->where('fleet_notifications.company_id',$company)->whereNull('read_at')->select('fleet_notifications.id','damage_id','description','registration_number')->orderByDesc('fleet_notifications.id')->limit(100)->get(),
            'unread_count'=>$role==='driver'?0:DB::table('fleet_notifications')->where('company_id',$company)->whereNull('read_at')->count()]);
    }

    public function handovers(Request $r, int $company)
    {
        $role=$this->access($r,$company,false);
        $q=DB::table('fleet_handovers as h')->leftJoin('users as f','f.id','=','h.from_driver_id')->leftJoin('users as t','t.id','=','h.to_driver_id')->leftJoin('users as a','a.id','=','h.recorded_by')->leftJoin('users as reviewer','reviewer.id','=','h.reviewed_by')->where('h.company_id',$company);
        if($role==='driver')$q->where(fn($x)=>$x->where('h.from_driver_id',$r->user()->id)->orWhere('h.to_driver_id',$r->user()->id));
        foreach(['vehicle_id','review_status','depot_name'] as $key)if($r->filled($key))$q->where('h.'.$key,$r->input($key));
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
        $role=$this->access($r,$company,false);$h=$this->handoverRow($company,$id);
        abort_if($role==='driver'&&!in_array((int)$r->user()->id,[(int)$h->from_driver_id,(int)$h->to_driver_id],true),403);
        $previous=DB::table('fleet_handovers')->where('vehicle_id',$h->vehicle_id)->where('id','<',$h->id)->orderByDesc('id')->first();
        $files=fn($row)=>!$row?[]:DB::table('fleet_files')->where('handover_id',$row->id)->get(['id','name','mime_type']);
        $legacy=fn($row)=>!$row?->inspection_id?[]:DB::table('vehicle_return_photos')->where('vehicle_return_inspection_id',$row->inspection_id)->get(['id','name','mime_type']);
        return $this->ok(['handover'=>$h,'files'=>$files($h),'legacy_photos'=>$legacy($h),'previous'=>$previous,'previous_files'=>$files($previous),'previous_legacy_photos'=>$legacy($previous),
            'damages'=>$role==='driver'?[]:DB::table('fleet_damages')->where('vehicle_id',$h->vehicle_id)->orderByDesc('id')->get()]);
    }

    public function createHandover(Request $r,int $company)
    {
        $role=$this->access($r,$company,false);
        $d=$r->validate(['vehicle_id'=>['required','integer'],'destination'=>['required','in:driver,depot,workshop,returned'],
            'to_driver_id'=>['nullable','required_if:destination,driver','integer'],'depot_name'=>['nullable','string','max:120','required_if:destination,depot'],
            'mileage_km'=>['required','integer','min:0','max:999999999'],'fuel_level_percent'=>['required','integer','between:0,100'],
            'has_damage'=>['required','boolean'],'notes'=>['nullable','required_if:has_damage,1,true','string','max:5000'],
            'photos'=>['required','array','min:3','max:10'],'photos.*'=>['file','mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif','max:12288']]);
        $paths=[];
        try {
            $id=DB::transaction(function()use($r,$company,$role,$d,&$paths){
                // Serialize dispatch changes company-wide, including a driver moving between vehicles.
                DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
                $v=Vehicle::where('company_id',$company)->whereKey($d['vehicle_id'])->lockForUpdate()->firstOrFail();
                abort_if($role==='driver'&&(int)$v->assigned_driver_user_id!==(int)$r->user()->id,403);
                abort_if($role==='driver'&&$d['destination']==='returned',403);
                $id=$this->fleet->handover($v,$r->user()->id,$d);
                foreach($r->file('photos',[]) as $file)$this->storeFile($file,$company,$id,null,$paths);
                return $id;
            });
        }catch(\Throwable $e){foreach($paths as $p)Storage::disk('local')->delete($p);throw $e;}
        return $this->ok(['id'=>$id]);
    }

    public function review(Request $r,int $company,int $id)
    {
        $this->access($r,$company);
        $d=$r->validate(['decision'=>['required','in:clear,damage'],'description'=>['nullable','required_if:decision,damage','string','max:10000'],
            'kind'=>['sometimes','in:internal,accident'],'block_vehicle'=>['sometimes','boolean'],'existing_damage_id'=>['nullable','integer']]);
        return DB::transaction(function()use($r,$company,$id,$d){
            $h=DB::table('fleet_handovers')->where('company_id',$company)->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($h->review_status==='pending',409,'Handover already reviewed.');
            $existing=!empty($d['existing_damage_id'])?DB::table('fleet_damages')->where('company_id',$company)->where('vehicle_id',$h->vehicle_id)->where('id',$d['existing_damage_id'])->first():null;
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
        $q=DB::table('fleet_damages as d')->join('vehicles as v','v.id','=','d.vehicle_id')->where('d.company_id',$company);
        if($r->filled('vehicle_id'))$q->where('d.vehicle_id',$r->input('vehicle_id'));
        if($r->filled('status'))$q->where('d.status',$r->input('status'));
        return $this->ok($q->select('d.*','v.registration_number')->orderByDesc('d.id')->paginate(50));
    }

    public function damageDetail(Request $r,int $company,int $id)
    {
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
        $this->access($r,$company);DB::table('fleet_damages')->where('company_id',$company)->where('id',$id)->firstOrFail();
        $r->validate(['files'=>['required','array','min:1','max:20'],'files.*'=>['required','file','mimes:pdf,jpg,jpeg,png,webp,heic,heif,doc,docx,xls,xlsx,txt','max:20480']]);
        $paths=[];try{DB::transaction(function()use($r,$company,$id,&$paths){foreach($r->file('files') as $f)$this->storeFile($f,$company,null,$id,$paths);$this->fleet->event($company,$r->user()->id,'damage',$id,'files_added');});}
        catch(\Throwable $e){foreach($paths as $p)Storage::disk('local')->delete($p);throw $e;}
        return $this->ok(['uploaded'=>count($paths)]);
    }

    public function file(Request $r,int $company,int $id)
    {
        $role=$this->access($r,$company,false);$f=DB::table('fleet_files')->where('company_id',$company)->where('id',$id)->firstOrFail();
        if($role==='driver'){$h=$f->handover_id?$this->handoverRow($company,$f->handover_id):null;abort_unless($h&&in_array((int)$r->user()->id,[(int)$h->from_driver_id,(int)$h->to_driver_id],true),403);}
        abort_unless(Storage::disk('local')->exists($f->path),404);
        return Storage::disk('local')->download($f->path,$f->name,['Content-Type'=>$f->mime_type,'X-Content-Type-Options'=>'nosniff']);
    }

    public function vehicle(Request $r,int $company,int $id)
    {
        $this->access($r,$company);$d=$r->validate(['status'=>['required',Rule::in(FleetOperations::STATUSES)],'depot_name'=>['nullable','string','max:120'],'dispatch_blocked'=>['required','boolean'],'reason'=>['required','string','max:2000']]);
        abort_if($d['status']==='returned',422,'Use a return handover for rented vehicles.');
        return DB::transaction(function()use($r,$company,$id,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($v->status,['sold','returned'],true),422,'Archived vehicle cannot be reactivated here.');
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
        $q=DB::table('fleet_tours as t')->leftJoin('users as d','d.id','=','t.driver_id')->leftJoin('users as a','a.id','=','t.locked_by')->leftJoin('vehicles as v','v.id','=','t.vehicle_id')->where('t.company_id',$company);
        if($role==='driver')$q->where('t.driver_id',$r->user()->id);
        $q->whereBetween('service_date',[$d['from']??now()->toDateString(),$d['to']??($d['from']??now()->toDateString())]);
        foreach(['depot_name','partner','status'] as $key)if($r->filled($key))$q->where('t.'.$key,$r->input($key));
        if($r->filled('search'))$q->where('tour_code','like','%'.mb_substr($r->input('search'),0,120).'%');
        return $this->ok($q->select('t.*','d.name as driver_name','a.name as locked_by_name','v.registration_number as selected_plate')->orderByDesc('service_date')->orderBy('depot_name')->orderBy('partner')->orderBy('tour_code')->paginate(100));
    }

    public function importTours(Request $r,int $company)
    {
        $this->access($r,$company);
        $d=$r->validate(['service_date'=>['required','date_format:Y-m-d'],'depot_name'=>['required','string','max:120'],
            'partner'=>['required','string','max:80'],'rows'=>['required','array','min:1','max:3000'],'rows.*.tour_code'=>['nullable','string','max:120'],
            'rows.*.driver_name'=>['nullable','string','max:255'],'confirm'=>['required','boolean']]);
        $d['depot_name']=trim($d['depot_name']);$d['partner']=trim($d['partner']);
        abort_if($d['depot_name']===''||$d['partner']==='',422,'Depot and partner are required.');
        $seen=[];$valid=[];$invalid=[];$duplicates=[];
        foreach($d['rows'] as $i=>$row){
            $code=trim($row['tour_code']??'');
            if($code===''){$invalid[]=['row'=>$i+1,'reason'=>'Missing tour ID'];continue;}
            $key=mb_strtolower($code);
            if(isset($seen[$key])||DB::table('fleet_tours')->where('company_id',$company)->where('service_date',$d['service_date'])->where('depot_name',$d['depot_name'])->where('partner',$d['partner'])->whereRaw('LOWER(tour_code) = ?',[$key])->exists()){$duplicates[]=['row'=>$i+1,'tour_code'=>$code];continue;}
            $seen[$key]=true;$valid[]=['tour_code'=>$code,'driver_name'=>$row['driver_name']??null];
        }
        $count=0;
        if($d['confirm']){
            $count=DB::transaction(function()use($r,$company,$d,$valid){
                DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();$count=0;
                foreach($valid as $row){$identity=['company_id'=>$company,'service_date'=>$d['service_date'],'depot_name'=>trim($d['depot_name']),'partner'=>trim($d['partner']),'tour_code'=>$row['tour_code']];
                    if(DB::table('fleet_tours')->where(collect($identity)->except('tour_code')->all())->whereRaw('LOWER(tour_code) = ?',[mb_strtolower($row['tour_code'])])->exists())continue;
                    DB::table('fleet_tours')->insert($identity+['source_driver_name'=>$row['driver_name']??null,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);$count++;
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
        $this->access($r,$company);
        $d=$r->validate(['driver_id'=>['nullable','integer'],'vehicle_id'=>['nullable','integer'],'reason'=>['nullable','string','max:2000'],
            'partner_url'=>['nullable','url:http,https','max:2000']]);
        return DB::transaction(function()use($r,$company,$id,$action,$d){
            DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
            $tour=DB::table('fleet_tours')->where('company_id',$company)->where('id',$id)->lockForUpdate()->firstOrFail();
            if($action==='assign'){
                abort_unless($tour->status==='open',422,'Reopen the tour with a reason before changing the assignment.');
                if(!empty($d['driver_id']))$this->fleet->driver($company,$d['driver_id']);
                if(!empty($d['vehicle_id']))abort_unless(Vehicle::where('company_id',$company)->whereKey($d['vehicle_id'])->exists(),422,'Vehicle belongs to another company.');
                DB::table('fleet_tours')->where('id',$id)->update(['driver_id'=>$d['driver_id']??null,'vehicle_id'=>$d['vehicle_id']??null,'partner_url'=>$d['partner_url']??null,'updated_at'=>now()]);
                $this->fleet->event($company,$r->user()->id,'tour',$id,'assigned',$d);
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

    public function tourDetail(Request $r,int $company,int $id)
    {
        $role=$this->access($r,$company,false);$tour=DB::table('fleet_tours')->where('company_id',$company)->where('id',$id)->firstOrFail();
        abort_if($role==='driver'&&(int)$tour->driver_id!==(int)$r->user()->id,403);
        return $this->ok(['tour'=>$tour,'events'=>DB::table('fleet_events as e')->leftJoin('users as u','u.id','=','e.actor_id')->where('e.company_id',$company)->where('entity_type','tour')->where('entity_id',$id)->select('e.*','u.name as actor_name')->orderByDesc('e.id')->get()]);
    }

    public function readNotification(Request $r,int $company,int $id)
    {
        $this->access($r,$company);DB::table('fleet_notifications')->where('company_id',$company)->where('id',$id)->update(['read_by'=>$r->user()->id,'read_at'=>now(),'updated_at'=>now()]);return $this->ok(['id'=>$id]);
    }
}
