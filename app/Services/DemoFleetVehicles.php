<?php
namespace App\Services;

use App\Http\Controllers\Api\VehicleController;
use App\Models\{User,Vehicle};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Finite operator-requested test fleet. Synthetic specs, never OEM or compliance evidence. */
final class DemoFleetVehicles
{
    public const COMPANY='Freightbook Logistics Hub';
    public const BATCH='freightbook-demo-vehicles-20261119';
    public static function identity(int $i):array {
        $rows=[
            ['Mercedes-Benz','Actros L','rigid_truck','box',9.2,2.55,4,'6x2'],
            ['Volvo','FH Aero','rigid_truck','curtainsider',10,2.55,4,'6x2'],
            ['Scania','S','tractor_unit','none',6.2,2.55,3.8,'4x2'],
            ['MAN','TGS','rigid_truck','tipper',8,2.55,3.5,'8x4'],
            ['DAF','XF','rigid_truck','refrigerated',9.8,2.55,4,'6x2'],
            ['Iveco','S-Way','rigid_truck','tanker',9.5,2.55,3.6,'6x2'],
            ['Volkswagen','Crafter','cargo_van','box',6.5,2.15,3.1,'4x2'],
            ['Ford','Transit Custom','cargo_van','panel_van',5.3,2,2,'4x2'],
            ['Renault','Master','cargo_van','panel_van',6.2,2.1,2.7,'4x2'],
            ['Fiat','Ducato','cargo_van','refrigerated',6.4,2.1,2.8,'4x2'],
        ];
        [$make,$model,$resource,$body,$length,$width,$height,$axles]=$rows[$i-1];
        return ['registration_number'=>'FB-DEMO-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'make'=>$make,'model'=>$model,'vehicle_type'=>$resource,'transport_type'=>'road','status'=>'available','ownership_type'=>'owned',
            'features'=>['resource_type'=>$resource,'body_type_code'=>$body,'body_type'=>['box'=>'Box truck','curtainsider'=>'Curtain sider','none'=>'Tractor unit','tipper'=>'Dump truck','refrigerated'=>'Refrigerated truck','tanker'=>'Tanker','panel_van'=>'Panel van'][$body],
                'length_m'=>$length,'width_m'=>$width,'height_m'=>$height,'axle_configuration'=>$axles,'demo_record'=>true,'demo_batch'=>self::BATCH,'specification_source'=>'synthetic_test_data']];
    }
    public function inspect():array {
        $c=DB::connection();$driver=$c->getConfig('driver');$host=$c->getConfig('host');$port=$c->getConfig('port');$database=$c->getConfig('database');
        $memory=$driver==='sqlite'&&$database===':memory:'&&$host===null&&($c->select('PRAGMA database_list')[0]->file??null)==='';
        if(!$memory){
            abort_unless(in_array($driver,['mysql','mariadb'],true)&&in_array($host,['localhost','127.0.0.1','::1'],true)&&$database==='tagnetba_smartfreight',409,'The demo database target is not verified. No records changed.');
            abort_unless(($c->selectOne('SELECT DATABASE() AS target')->target??null)===$database,409,'Database mismatch.');
        }
        $companies=DB::table('companies')->where('name',self::COMPANY)->get(['id','name','owner_user_id']);
        abort_unless($companies->count()===1,409,'Expected exactly one demo company.');$company=$companies->first();User::findOrFail($company->owner_user_id);
        $existing=[];
        foreach(range(1,10) as $i){$identity=self::identity($i);$matches=Vehicle::where('registration_number',$identity['registration_number'])->get();
            abort_if($matches->count()>1,409,'Duplicate demo registration. No records changed.');
            if($v=$matches->first()){
                abort_unless((int)$v->company_id===(int)$company->id&&($v->features['demo_batch']??'')===self::BATCH&&($v->features['demo_record']??false)===true,409,'A demo identifier is already in use. Nothing was overwritten.');
                $existing[]=['id'=>$v->id,'registration_number'=>$v->registration_number];
            }
        }
        return ['database'=>['driver'=>$driver,'host'=>$host,'port'=>$port,'name'=>$database],'fingerprint'=>hash('sha256',json_encode([$driver,$host,$port,$database,$company->id,self::COMPANY])),'company_id'=>$company->id,'company_name'=>$company->name,'owner_user_id'=>$company->owner_user_id,'existing'=>$existing,'to_create'=>10-count($existing),'changes'=>0];
    }
    public function create(string $fingerprint):array {
        $before=$this->inspect();abort_unless(hash_equals($before['fingerprint'],$fingerprint),409,'Target changed. Repeat read-only inspection.');
        return DB::transaction(function()use($before){
            DB::table('companies')->where('id',$before['company_id'])->lockForUpdate()->firstOrFail();$current=$this->inspect();abort_unless(hash_equals($before['fingerprint'],$current['fingerprint']),409);
            $actor=User::findOrFail($before['owner_user_id']);$created=0;$rows=[];
            foreach(range(1,10) as $i){$data=self::identity($i);$v=Vehicle::where('registration_number',$data['registration_number'])->first();
                if(!$v){$r=Request::create('/authorized-demo-vehicles','POST',$data+['company_id'=>$before['company_id']]);$r->setUserResolver(fn()=>$actor);app(VehicleController::class)->store($r);$v=Vehicle::where('registration_number',$data['registration_number'])->firstOrFail();$created++;}
                $rows[]=['id'=>$v->id,'registration_number'=>$v->registration_number,'make'=>$v->make,'model'=>$v->model,'body_type_code'=>$v->features['body_type_code'],'status'=>$v->status];
            }
            if($created)app(FleetOperations::class)->event($before['company_id'],$actor->id,'company',$before['company_id'],'demo_vehicles_created',['created'=>$created,'source'=>'signed_operator_request','vehicle_ids'=>array_column($rows,'id')]);
            return ['company_id'=>$before['company_id'],'company_name'=>$before['company_name'],'created'=>$created,'reused'=>10-$created,'vehicles'=>$rows];
        });
    }
}
