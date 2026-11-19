<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyTeamAccess;
use App\Services\FleetDepotAccess;
use App\Services\FleetOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class FleetDepotController extends Controller
{
    public function __construct(private FleetDepotAccess $access) {}
    private function ok($data) { return response()->json(['data'=>$data]); }

    public function index(Request $r,int $company)
    {
        $s=$this->access->authorize($r->user(),$company);
        $q=DB::table('fleet_depots')->where('company_id',$company);
        if (!$s['all']) $q->whereIn('id',$s['depots']);
        return $this->ok(['depots'=>$q->orderBy('name')->get(),'abilities'=>$s['abilities'],'role'=>$s['role']]);
    }

    public function save(Request $r,int $company,?int $id=null)
    {
        $this->access->authorize($r->user(),$company,'depots');
        $d=$r->validate(['code'=>['required','string','max:40',Rule::unique('fleet_depots')->where('company_id',$company)->ignore($id)],
            'name'=>['required','string','max:120'],'city'=>['nullable','string','max:120'],'address'=>['nullable','string','max:255'],'active'=>['required','boolean']]);
        abort_if(trim($d['code'])===''||trim($d['name'])==='',422);
        return DB::transaction(function()use($r,$company,$id,$d){
            if($id) { DB::table('fleet_depots')->where('company_id',$company)->where('id',$id)->firstOrFail(); DB::table('fleet_depots')->where('id',$id)->update($d+['updated_at'=>now()]); }
            else $id=DB::table('fleet_depots')->insertGetId($d+['company_id'=>$company,'created_at'=>now(),'updated_at'=>now()]);
            app(FleetOperations::class)->event($company,$r->user()->id,'depot',$id,'updated',$d);
            return $this->ok(['id'=>$id]);
        });
    }

    public function permissions(Request $r,int $company)
    {
        $this->access->authorize($r->user(),$company,'permissions');
        $members=User::whereHas('companies',fn($q)=>$q->where('companies.id',$company)->where('company_user.status','active'))->get(['id','name']);
        return $this->ok(['members'=>$members,'scopes'=>DB::table('fleet_member_scopes')->where('company_id',$company)->get()]);
    }

    public function grant(Request $r,int $company,int $user)
    {
        $this->access->authorize($r->user(),$company,'permissions');
        $d=$r->validate(['fleet_role'=>['required','in:fleet_manager,depot_manager,dispatcher,workshop,driver'],
            'all_depots'=>['required','boolean'],'depot_ids'=>['required','array'],'depot_ids.*'=>['integer','distinct'],
            'vehicle_ids'=>['sometimes','array'],'vehicle_ids.*'=>['integer','distinct']]);
        $member=User::findOrFail($user);$role=app(CompanyTeamAccess::class)->companyRole($member,$company);
        abort_unless($role&&$role!=='company'&&!$member->isSuperAdminOrMaster(),422,'Select an active team member.');
        abort_unless(($role==='driver')===($d['fleet_role']==='driver'),422,'Driver membership must keep the driver role.');
        if($d['fleet_role']==='driver')$d['all_depots']=false;
        foreach($d['depot_ids'] as $id)abort_unless(DB::table('fleet_depots')->where('company_id',$company)->where('id',$id)->exists(),422);
        foreach($d['vehicle_ids']??[] as $id)abort_unless(Vehicle::where('company_id',$company)->whereKey($id)->exists(),422);
        if($d['fleet_role']==='workshop') { $d['all_depots']=false;$d['depot_ids']=[]; }
        return DB::transaction(function()use($r,$company,$user,$d){
            DB::table('fleet_member_scopes')->updateOrInsert(['company_id'=>$company,'user_id'=>$user],
                ['fleet_role'=>$d['fleet_role'],'all_depots'=>$d['all_depots'],'depot_ids'=>json_encode($d['depot_ids']),
                'vehicle_ids'=>json_encode($d['vehicle_ids']??[]),'created_at'=>now(),'updated_at'=>now()]);
            app(FleetOperations::class)->event($company,$r->user()->id,'fleet_permission',$user,'updated',$d);
            return $this->ok(['id'=>$user]);
        });
    }

    public function overview(Request $r,int $company)
    {
        $s=$this->access->authorize($r->user(),$company);
        $q=$this->access->query(Vehicle::where('company_id',$company),$r->user(),$company,'vehicle');
        if($r->filled('depot_id')) {
            if($r->input('depot_id')==='unassigned')$q->whereNull('depot_id');
            else { $this->access->depot($r->user(),$company,$r->integer('depot_id'),false);$q->where('depot_id',$r->integer('depot_id')); }
        }
        if($r->filled('search')) { $term='%'.mb_substr($r->string('search')->toString(),0,120).'%';$q->where(fn($x)=>$x->where('registration_number','like',$term)->orWhere('vin','like',$term)->orWhere('make','like',$term)->orWhere('model','like',$term)); }
        $counts=(clone $q)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total','status');
        if($r->filled('status'))$q->where('status',$r->input('status'));
        elseif(!$r->boolean('include_archived'))$q->whereNotIn('status',['sold','returned','stolen']);
        $rows=$q->with(['assignedDriver:id,name'])->orderBy('registration_number')->paginate(25);
        $depots=DB::table('fleet_depots')->where('company_id',$company)->pluck('name','id');
        $ids=$rows->getCollection()->pluck('id');
        $mileages=DB::table('fleet_handovers')->where('company_id',$company)->whereIn('vehicle_id',$ids)
            ->selectRaw('vehicle_id, MAX(mileage_km) as mileage')->groupBy('vehicle_id')->pluck('mileage','vehicle_id');
        $tours=$this->access->query(DB::table('fleet_tours')->where('company_id',$company),$r->user(),$company,'tour')
            ->whereIn('vehicle_id',$ids)->whereDate('service_date',now()->toDateString())->whereIn('status',['open','locked'])
            ->get(['id','vehicle_id','tour_code','partner','status'])->groupBy('vehicle_id');
        $rows->getCollection()->transform(function($v)use($depots,$mileages,$tours){
            $v->depot_label=$depots[$v->depot_id]??$v->depot_name;
            $v->last_mileage=$mileages[$v->id]??null;
            $v->active_tours=$tours->get($v->id,collect())->values();return $v;
        });
        return $this->ok(['vehicles'=>$rows,'counts'=>$counts,'total'=>array_sum($counts->all()),'abilities'=>$s['abilities']]);
    }

    public function detail(Request $r,int $company,int $id)
    {
        $v=Vehicle::where('company_id',$company)->with(['assignedDriver:id,name','latestLocation'])->findOrFail($id);
        $this->access->vehicle($r->user(),$v);
        $s=$this->access->authorize($r->user(),$company);
        $handovers=$this->access->query(DB::table('fleet_handovers')->where('company_id',$company),$r->user(),$company,'handover')->where('vehicle_id',$id);
        $h=(clone $handovers)->orderByDesc('effective_at')->orderByDesc('id')->limit(30)->get();
        $v->last_mileage=(clone $handovers)->max('mileage_km');
        $v->active_tours=$this->access->query(DB::table('fleet_tours')->where('company_id',$company),$r->user(),$company,'tour')
            ->where('vehicle_id',$id)->whereDate('service_date',now()->toDateString())->whereIn('status',['open','locked'])->get(['id','tour_code','partner','status']);
        $damageQuery=in_array('damage',$s['abilities'])?$this->access->query(DB::table('fleet_damages')->where('company_id',$company),$r->user(),$company,'damage')->where('vehicle_id',$id):null;
        $damages=$damageQuery?(clone $damageQuery)->orderByDesc('id')->limit(30)->get():[];
        $events=$s['admin']||$s['all']?DB::table('fleet_events')->where('company_id',$company)->where('entity_type','vehicle')->where('entity_id',$id)->orderByDesc('id')->limit(50)->get():[];
        return $this->ok(['vehicle'=>$v,'handovers'=>$h,'damages'=>$damages,'events'=>$events,
            'last_handover'=>$h->first(),'pending_handovers'=>(clone $handovers)->where('review_status','pending')->count(),
            'open_damages'=>$damageQuery?(clone $damageQuery)->where('status','!=','closed')->count():null,
            'home_depot'=>$v->home_depot_id?DB::table('fleet_depots')->where('company_id',$company)->where('id',$v->home_depot_id)->first():null,
            'depot'=>$v->depot_id?DB::table('fleet_depots')->where('company_id',$company)->where('id',$v->depot_id)->first():null,'abilities'=>$s['abilities']]);
    }

    public function assignDepot(Request $r,int $company,int $id)
    {
        $this->access->authorize($r->user(),$company,'depots');
        $d=$r->validate(['home_depot_id'=>['nullable','integer'],'depot_id'=>['nullable','integer'],'reason'=>['required','string','max:2000']]);
        foreach(['home_depot_id','depot_id'] as $key)$this->access->depot($r->user(),$company,$d[$key]??null);
        return DB::transaction(function()use($r,$company,$id,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($v->assigned_driver_user_id&&($d['depot_id']??null)!=$v->depot_id,422,'Use a handover to transfer a vehicle currently assigned to a driver.');
            $before=$v->only(['home_depot_id','depot_id']);
            $v->home_depot_id=$d['home_depot_id']??null;$v->depot_id=$d['depot_id']??null;
            $v->depot_name=$v->depot_id?DB::table('fleet_depots')->where('id',$v->depot_id)->value('name'):null;$v->save();
            app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'depot_changed',['before'=>$before,'after'=>$d]);
            return $this->ok($v);
        });
    }

    public function service(Request $r,int $company,int $id)
    {
        $d=$r->validate(['next_service'=>['nullable','string','max:120'],'reason'=>['required','string','max:2000']]);
        return DB::transaction(function()use($r,$company,$id,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->vehicle($r->user(),$v,'service');
            abort_if(in_array($v->status,['sold','returned'],true),422,'Archived vehicle is read-only.');
            $features=$v->features?:[];$before=$features['next_service']??null;$features['next_service']=$d['next_service']??null;
            $v->features=$features;$v->save();
            app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'service_updated',['before'=>$before,'after'=>$d]);
            return $this->ok(['id'=>$id]);
        });
    }
}
