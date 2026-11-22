<?php
namespace App\Services;

use App\Models\User;
use App\Services\Accounting\AccountingAccess;
use App\Services\Accounting\BusinessWorkspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Private editing snapshots, never operational records or a permission grant. */
final class FormDrafts
{
    public const RESOURCES=['vehicles','drivers','customers','companies','users','loads','invoices','documents','warehouses','crm_documents','shipment_workspaces','fleet_vehicle_tasks','fleet_tours','accounting_partners','accounting_entries','accounting_bank_transactions','accounting_cost_estimates'];
    public function authorize(User $user, ?int $company): void
    {
        if ($company && BusinessWorkspace::isPersonal($company)) {
            app(AccountingAccess::class)->authorizePersonal($user, $company);
        } elseif ($company) {
            app(CompanyTeamAccess::class)->authorize($user, $company);
        }
    }
    public function scope(User $user,array $d):string {return hash('sha256',json_encode([$user->id,(int)($d['company_id']??0),$d['module'],$d['form_key'],$d['record_key']??'new']));}
    public static function clean(mixed $value):mixed {
        if(!is_array($value))return $value;
        $out=[];foreach($value as $key=>$item){if(is_string($key)&&preg_match('/password|passphrase|secret|token|card.?number|card.?expiry|card.?cvc|cvv|cvc|api.?key|authorization/i',$key))continue;$out[$key]=self::clean($item);}return $out;
    }
    public function pack(array $value):string {return Crypt::encryptString(json_encode(self::clean($value),JSON_THROW_ON_ERROR));}
    public function unpack(string $value):array {return json_decode(Crypt::decryptString($value),true,512,JSON_THROW_ON_ERROR);}
    public function accessHash(User $user,?int $company):string {
        $u=(array)DB::table('users')->where('id',$user->id)->first();$stamp=['user'=>array_intersect_key($u,array_flip(['id','role_id','is_active']))];
        if($company){$stamp['owner']=BusinessWorkspace::ownerId($company);foreach(['company_user','fleet_member_scopes','accounting_permissions'] as $table)if(Schema::hasTable($table))$stamp[$table]=DB::table($table)->where(['company_id'=>$company,'user_id'=>$user->id])->get()->map(fn($row)=>(array)$row)->all();}
        return $this->hash((object)$stamp);
    }
    public function record(User $user,?int $company,string $resource,int $id,bool $lock=false):object {
        abort_unless(in_array($resource,self::RESOURCES,true)&&Schema::hasTable($resource),422,'Unsupported draft record.');
        $query=DB::table($resource)->where('id',$id);if($lock)$query->lockForUpdate();$record=$query->first();abort_unless($record,404,'Draft record no longer exists.');
        $this->authorize($user,$company);
        if($resource==='vehicles'){app(FleetDepotAccess::class)->vehicle($user,\App\Models\Vehicle::findOrFail($id));abort_if($company&&(int)$record->company_id!==$company,403);return $record;}
        $recordCompany=$record->company_id??$record->provider_company_id??$record->primary_company_id??($resource==='companies'?$id:null);
        if($company&&$recordCompany)abort_unless((int)$recordCompany===$company,403);
        if($recordCompany){$this->authorize($user,(int)$recordCompany);return $record;}
        if($user->isSuperAdminOrMaster())return $record;
        $owns=(int)($record->owner_user_id??$record->user_id??$record->uploaded_by_user_id??$record->created_by_user_id??0)===(int)$user->id;
        if($resource==='users')$owns=$id===(int)$user->id;
        abort_unless($owns,403,'This record is outside your draft scope.');return $record;
    }
    public function hash(object $record):string {
        $normalise=function($value)use(&$normalise){if(is_string($value)&&in_array(substr(ltrim($value),0,1),['{','['],true)){try{$decoded=json_decode($value,true,512,JSON_THROW_ON_ERROR);if(is_array($decoded))$value=$decoded;}catch(\JsonException){}}if(is_object($value))$value=(array)$value;if(is_array($value)){if(!array_is_list($value))ksort($value);return array_map($normalise,$value);}return $value;};
        return hash('sha256',json_encode($normalise((array)$record),JSON_THROW_ON_ERROR));
    }
    public function present(object $row,bool $payload=true):array {
        $out=(array)$row;unset($out['active_scope'],$out['request_hash'],$out['result'],$out['result_status'],$out['access_hash'],$out['access_company_id']);
        if($payload)$out['payload']=$this->unpack($row->payload);else unset($out['payload']);
        return $out;
    }
}
