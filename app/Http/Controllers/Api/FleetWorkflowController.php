<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Vehicle;
use App\Services\FleetDepotAccess;
use App\Services\FleetOperations;
use App\Services\VehicleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FleetWorkflowController extends Controller
{
    public function __construct(private FleetDepotAccess $access) {}
    public static function snapshot(Vehicle $v): array {
        $f=$v->features??[];$keys=['resource_type','legal_category','body_type_code','body_variant',...VehicleRegistry::NUMBERS,'pallet_positions','axle_configuration'];
        $features=[];foreach($keys as $key)$features[$key]=$f[$key]??null;
        return ['registration_number'=>$v->registration_number,'vin'=>$v->vin,'transport_type'=>$v->transport_type,'capacity_kg'=>$v->capacity_kg,'capacity_m3'=>$v->capacity_m3,'features'=>$features];
    }
    public static function fingerprint(Vehicle $v): string { return hash('sha256',json_encode(self::snapshot($v),JSON_THROW_ON_ERROR)); }
    private function vehicle(Request $r,int $company,int $id,string $ability='view'): Vehicle {
        $v=Vehicle::where('company_id',$company)->findOrFail($id);$this->access->vehicle($r->user(),$v,$ability);return $v;
    }
    public function index(Request $r,int $company,int $id) {
        $v=$this->vehicle($r,$company,$id);$tasks=DB::table('fleet_vehicle_tasks')->where('company_id',$company)->where('vehicle_id',$id)->orderBy('status')->orderBy('due_on')->get();
        $mileage=DB::table('fleet_handovers')->where('company_id',$company)->where('vehicle_id',$id)->max('mileage_km');
        foreach($tasks as $task){$task->due=$task->status==='open'&&(($task->due_on&&$task->due_on<=now()->toDateString())||($task->due_mileage!==null&&$mileage!==null&&$task->due_mileage<=$mileage));$task->soon=$task->status==='open'&&$task->due_on&&$task->due_on<=now()->addDays(30)->toDateString();}
        $review=DB::table('fleet_vehicle_reviews')->where('company_id',$company)->where('vehicle_id',$id)->orderByDesc('id')->first();
        if($review){$review->current=hash_equals($review->fingerprint,self::fingerprint($v));unset($review->snapshot);}
        $scope=$this->access->authorize($r->user(),$company);$members=$scope['role']==='workshop'?collect([['id'=>$r->user()->id,'name'=>$r->user()->name]]):DB::table('users')->whereIn('id',DB::table('company_user')->where('company_id',$company)->where('status','active')->select('user_id'))->orWhere('id',DB::table('companies')->where('id',$company)->value('owner_user_id'))->get(['id','name']);
        return response()->json(['data'=>['tasks'=>$tasks,'review'=>$review,'fingerprint'=>self::fingerprint($v),'warnings'=>VehicleRegistry::warnings($v),'members'=>$members]]);
    }
    public function alerts(Request $r,int $company) {
        $this->access->authorize($r->user(),$company);$ids=$this->access->query(Vehicle::where('company_id',$company)->whereNotIn('status',['sold','returned','stolen']),$r->user(),$company,'vehicle')->select('vehicles.id');
        if($r->filled('depot_id')){if($r->input('depot_id')==='unassigned')$ids->whereNull('depot_id');else{$this->access->depot($r->user(),$company,$r->integer('depot_id'),false);$ids->where('depot_id',$r->integer('depot_id'));}}
        $q=DB::table('fleet_vehicle_tasks as t')->join('vehicles as v','v.id','=','t.vehicle_id')->leftJoin('users as u','u.id','=','t.responsible_user_id')->where('t.company_id',$company)->whereIn('t.vehicle_id',$ids)->where('t.status','open')->where(function($q){$q->where('t.due_on','<=',now()->addDays(30)->toDateString())->orWhereRaw('t.due_mileage <= (SELECT MAX(h.mileage_km) FROM fleet_handovers h WHERE h.company_id=t.company_id AND h.vehicle_id=t.vehicle_id)')->orWhere(fn($q)=>$q->where('t.kind','document')->where('t.review_status','pending'));});
        return response()->json(['data'=>$q->select('t.*','v.registration_number','u.name as responsible_name')->orderBy('t.due_on')->paginate(25)]);
    }
    public function review(Request $r,int $company,int $id) {
        $this->vehicle($r,$company,$id,'create');
        $d=$r->validate(['evidence_reference'=>['required','string','max:255'],'document_id'=>['nullable','integer'],'fingerprint'=>['required','regex:/^[a-f0-9]{64}$/']]);
        abort_unless(trim($d['evidence_reference']),422,'Enter the document reference.');
        return DB::transaction(function()use($r,$company,$id,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();$this->access->vehicle($r->user(),$v,'create');
            abort_unless(hash_equals(self::fingerprint($v),$d['fingerprint']),409,'Vehicle data changed. Reload before reviewing.');
            abort_if(VehicleRegistry::warnings($v)!==[],422,'Correct and classify the vehicle before confirming the record.');
            if(($v->transport_type??'road')==='road')abort_unless(!empty($v->features['gross_weight_kg']),422,'Enter the documented gross mass.');
            app(VehicleRegistry::class)->validate(['capacity_kg'=>$v->capacity_kg,'capacity_m3'=>$v->capacity_m3,'transport_type'=>$v->transport_type??'road','features'=>$v->features],$v);
            if(!empty($d['document_id']))Document::where('vehicle_id',$id)->whereKey($d['document_id'])->firstOrFail();
            $review=DB::table('fleet_vehicle_reviews')->insertGetId(['company_id'=>$company,'vehicle_id'=>$id,'reviewed_by'=>$r->user()->id,'document_id'=>$d['document_id']??null,'evidence_reference'=>$d['evidence_reference'],'fingerprint'=>$d['fingerprint'],'snapshot'=>json_encode(self::snapshot($v)),'created_at'=>now(),'updated_at'=>now()]);
            app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'registry_reviewed',['review_id'=>$review]);
            return response()->json(['data'=>['id'=>$review]]);
        });
    }
    public function storeTask(Request $r,int $company,int $id) {
        $this->vehicle($r,$company,$id,'service');
        $d=$r->validate(['kind'=>['required','in:service,document'],'title'=>['required','string','max:160'],'due_on'=>['nullable','date_format:Y-m-d'],'due_mileage'=>['nullable','integer','min:0','max:999999999'],'responsible_user_id'=>['required','integer'],'document_id'=>['nullable','integer']]);
        abort_unless(trim($d['title'])&&(!empty($d['due_on'])||isset($d['due_mileage'])),422,'Enter a title and a date or mileage deadline.');
        if($d['kind']==='document')abort_unless(!empty($d['document_id'])&&!empty($d['due_on']),422,'Select the uploaded document and its expiry date.');
        return DB::transaction(function()use($r,$company,$id,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();$this->access->vehicle($r->user(),$v,'service');
            abort_if(in_array($v->status,['sold','returned','stolen']),422,'Archived vehicle is read-only.');
            $owner=DB::table('companies')->where('id',$company)->value('owner_user_id');
            $scope=$this->access->authorize($r->user(),$company);if($scope['role']==='workshop')abort_unless((int)$d['responsible_user_id']===(int)$r->user()->id,403);
            abort_unless((int)$owner===(int)$d['responsible_user_id']||DB::table('company_user')->where(['company_id'=>$company,'user_id'=>$d['responsible_user_id'],'status'=>'active'])->exists(),422,'Responsible person must belong to this company.');
            if(!empty($d['document_id']))Document::where('vehicle_id',$id)->whereKey($d['document_id'])->firstOrFail();
            $task=DB::table('fleet_vehicle_tasks')->insertGetId($d+['company_id'=>$company,'vehicle_id'=>$id,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'task_created',['task_id'=>$task]);return response()->json(['data'=>['id'=>$task]]);
        });
    }
    public function updateTask(Request $r,int $company,int $id,int $task) {
        $this->vehicle($r,$company,$id,'service');$d=$r->validate(['action'=>['required','in:complete,review,reject'],'reason'=>['required','string','max:2000']]);abort_unless(trim($d['reason']),422);
        return DB::transaction(function()use($r,$company,$id,$task,$d){
            $v=Vehicle::where('company_id',$company)->whereKey($id)->lockForUpdate()->firstOrFail();$this->access->vehicle($r->user(),$v,'service');
            $row=DB::table('fleet_vehicle_tasks')->where(['id'=>$task,'company_id'=>$company,'vehicle_id'=>$id])->lockForUpdate()->firstOrFail();abort_unless($row->status==='open',409,'Task is already closed.');
            if($d['action']!=='complete')abort_unless($row->kind==='document',422);
            $changes=['resolution'=>$d['reason'],'updated_at'=>now()];
            if($d['action']==='complete')$changes['status']='done';else $changes+=['review_status'=>$d['action']==='review'?'reviewed':'rejected','reviewed_by'=>$r->user()->id,'reviewed_at'=>now()];
            DB::table('fleet_vehicle_tasks')->where('id',$task)->update($changes);app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'task_'.$d['action'],['task_id'=>$task,'reason'=>$d['reason']]);return response()->json(['data'=>['id'=>$task]]);
        });
    }
}
