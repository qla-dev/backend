<?php
namespace App\Services;

use App\Http\Controllers\Api\DriverController;
use App\Models\{Driver,Role,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Validation\ValidationException;

/** A finite, explicitly requested demo batch. Never invoked by migrations, seeders or deployment. */
final class DemoFleetDrivers
{
    public const COMPANY='Freightbook Logistics Hub';
    public static function identity(int $number):array {
        $n=str_pad((string)$number,2,'0',STR_PAD_LEFT);
        return ['name'=>'Demo Vozač '.$n,'username'=>'fb-demo-driver-'.$n,'email'=>'fb-demo-driver-'.$n.'@example.invalid','license_number'=>'DEMO-FB-DRIVER-'.$n];
    }
    public function inspect():array {
        $c=DB::connection();$driver=$c->getConfig('driver');$host=$c->getConfig('host');$port=$c->getConfig('port');$database=$c->getConfig('database');
        $memory=$driver==='sqlite'&&$database===':memory:'&&$host===null&&($c->select('PRAGMA database_list')[0]->file??null)==='';
        if(!$memory){
            abort_unless(in_array($driver,['mysql','mariadb'],true)&&in_array($host,['localhost','127.0.0.1','::1'],true)&&$database==='tagnetba_smartfreight',409,'The demo database target is not verified. No records changed.');
            abort_unless(($c->selectOne('SELECT DATABASE() AS target')->target??null)===$database,409,'Database mismatch.');
        }
        $companies=DB::table('companies')->where('name',self::COMPANY)->get(['id','name','owner_user_id']);
        abort_unless($companies->count()===1,409,'The named demo company must match exactly one record.');$company=$companies->first();
        User::findOrFail($company->owner_user_id);$role=Role::where('name','driver')->firstOrFail();
        $existing=[];
        for($i=1;$i<=10;$i++){
            $identity=self::identity($i);$user=User::where('username',$identity['username'])->orWhere('email',$identity['email'])->first();$profile=Driver::where('license_number',$identity['license_number'])->first();
            if($user||$profile){
                abort_unless($user&&$profile&&(int)$profile->user_id===(int)$user->id&&(int)$profile->primary_company_id===(int)$company->id&&$user->username===$identity['username']&&$user->email===$identity['email']&&$user->name===$identity['name']&&(int)$user->role_id===(int)$role->id,409,'A demo identifier is already used by a different record. Nothing was overwritten.');
                $existing[]=['user_id'=>$user->id,'driver_id'=>$profile->id,...$identity];
            }
        }
        return ['database'=>['driver'=>$driver,'host'=>$host,'port'=>$port,'name'=>$database],'fingerprint'=>hash('sha256',json_encode([$driver,$host,$port,$database,$company->id,self::COMPANY])),'company_id'=>$company->id,'company_name'=>$company->name,'owner_user_id'=>$company->owner_user_id,'existing'=>$existing,'to_create'=>10-count($existing),'changes'=>0];
    }
    public function create(string $fingerprint,array $credentials):array {
        $preflight=$this->inspect();abort_unless(hash_equals($preflight['fingerprint'],$fingerprint),409,'Database or company changed. Repeat the read-only inspection.');
        if(count($credentials)!==10)throw ValidationException::withMessages(['credentials'=>'Exactly ten demo credentials are required.']);
        foreach(range(1,10) as $i){$identity=self::identity($i);$row=$credentials[$i-1]??[];if(($row['username']??'')!==$identity['username']||!is_string($row['password']??null)||strlen($row['password'])<20)throw ValidationException::withMessages(['credentials'=>'Unexpected demo identity or password format.']);}
        return DB::transaction(function()use($preflight,$credentials){
            DB::table('companies')->where('id',$preflight['company_id'])->lockForUpdate()->firstOrFail();$current=$this->inspect();abort_unless(hash_equals($preflight['fingerprint'],$current['fingerprint']),409);
            $role=Role::where('name','driver')->firstOrFail();$actor=User::findOrFail($preflight['owner_user_id']);$rows=[];$created=0;
            foreach(range(1,10) as $i){
                $identity=self::identity($i);$credential=$credentials[$i-1];$user=User::where('username',$identity['username'])->first();
                if($user){abort_unless(Hash::check($credential['password'],$user->password),409,'Existing demo password differs. Existing credentials were not reset.');$profile=Driver::where('user_id',$user->id)->where('license_number',$identity['license_number'])->firstOrFail();}
                else {
                    $request=Request::create('/authorized-demo-batch','POST',$identity+['password'=>$credential['password'],'language'=>'bs','country_code'=>'BA','primary_company_id'=>$preflight['company_id'],'license_country_code'=>'BA','license_expires_at'=>now()->addYear()->toDateString(),'availability_status'=>'available']);$request->setUserResolver(fn()=>$actor);
                    app(DriverController::class)->store($request);$user=User::where('username',$identity['username'])->firstOrFail();$profile=Driver::where('user_id',$user->id)->firstOrFail();
                    DB::table('company_user')->where(['company_id'=>$preflight['company_id'],'user_id'=>$user->id])->update(['company_role_id'=>$role->id,'business_departments'=>json_encode(['operations']),'subdepartments'=>json_encode(['road'])]);
                    $user->update(['bio'=>'DEMO profil za Freightbook testiranje. Nije stvarni vozač.']);$created++;
                }
                $rows[]=['user_id'=>$user->id,'driver_id'=>$profile->id,'company_role'=>app(CompanyTeamAccess::class)->companyRole($user,$preflight['company_id']),'active'=>$profile->is_active,...$identity];
            }
            if($created)app(FleetOperations::class)->event($preflight['company_id'],$actor->id,'company',$preflight['company_id'],'demo_drivers_created',['created'=>$created,'source'=>'signed_operator_request','user_ids'=>array_column($rows,'user_id')]);
            return ['company_id'=>$preflight['company_id'],'company_name'=>$preflight['company_name'],'created'=>$created,'reused'=>10-$created,'profiles'=>$rows,'messages_sent'=>0,'vehicles_assigned'=>0];
        });
    }
}
