<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/** Every endpoint uses this scope, including file downloads and historical records. */
final class FleetDepotAccess
{
    public function scope(User $user, int $company): array
    {
        $base=app(CompanyTeamAccess::class)->role($user,$company);
        if (!$base && (int)$user->driver?->primary_company_id===$company) $base='driver';
        abort_unless($base,403);
        if (in_array($base,['superadmin','master','company'],true)) return ['role'=>$base,'all'=>true,'depots'=>[],'vehicles'=>[],'admin'=>true];
        if ($base==='driver') {
            $ids=DB::table('fleet_member_scopes')->where('company_id',$company)->where('user_id',$user->id)->value('depot_ids');
            return ['role'=>'driver','all'=>false,'depots'=>json_decode($ids??'[]',true)?:[],'vehicles'=>[],'admin'=>false];
        }
        $grant=DB::table('fleet_member_scopes')->where('company_id',$company)->where('user_id',$user->id)->first();
        abort_unless($grant,403,'Ask your company administrator to assign fleet access.');
        return ['role'=>$grant->fleet_role,'all'=>(bool)$grant->all_depots,'depots'=>json_decode($grant->depot_ids,true)?:[],
            'vehicles'=>json_decode($grant->vehicle_ids??'[]',true)?:[],'admin'=>false];
    }

    public function authorize(User $user,int $company,string $ability='view'): array
    {
        $s=$this->scope($user,$company);
        $abilities=$s['admin']?['view','handover','review','damage','dispatch','status','create','depots','permissions','service']:
            match($s['role']) {
                'fleet_manager','depot_manager'=>['view','handover','review','damage','dispatch','status','create','service'],
                'dispatcher'=>['view','handover','review','damage','dispatch'],
                'driver'=>['view','handover'],
                'workshop'=>['view','service','status'], default=>[],
            };
        abort_unless(in_array($ability,$abilities,true),403);
        return $s+['abilities'=>$abilities];
    }

    public function query($q,User $user,int $company,string $kind,string $alias='')
    {
        $s=$this->authorize($user,$company);
        $p=$alias!==''?$alias.'.':'';
        if ($s['admin'] || ($s['all'] && $s['role']!=='workshop')) return $q;
        if ($s['role']==='driver') {
            return match($kind) {
                'vehicle'=>$q->where($p.'assigned_driver_user_id',$user->id),
                'handover'=>$q->where(fn($x)=>$x->where($p.'from_driver_id',$user->id)->orWhere($p.'to_driver_id',$user->id)),
                'tour'=>$q->where($p.'driver_id',$user->id), default=>$q->whereRaw('1=0'),
            };
        }
        if ($s['role']==='workshop') {
            return $kind==='vehicle'?$q->whereIn($p.'id',$s['vehicles']):$q->whereRaw('1=0');
        }
        return $q->where(function($x)use($s,$kind,$p){
            $x->whereIn($p.'depot_id',$s['depots']);
            if ($kind==='handover') $x->orWhereIn($p.'from_depot_id',$s['depots']);
        });
    }

    public function vehicle(User $user,Vehicle $v,string $ability='view'): void
    {
        if (!$v->company_id) { abort_unless($user->isSuperAdminOrMaster()||(int)$v->owner_user_id===(int)$user->id,403); return; }
        $this->authorize($user,(int)$v->company_id,$ability);
        abort_unless($this->query(Vehicle::query(),$user,(int)$v->company_id,'vehicle')->whereKey($v->id)->exists(),403);
    }

    public function depot(User $user,int $company,?int $id,bool $active=true): ?object
    {
        $s=$this->authorize($user,$company);
        if (!$id) { abort_unless($s['all']&&$s['role']!=='workshop',403,'Select an assigned depot.'); return null; }
        $d=DB::table('fleet_depots')->where('company_id',$company)->where('id',$id)->firstOrFail();
        $own=$s['role']==='driver'&&Vehicle::where('company_id',$company)->where('assigned_driver_user_id',$user->id)->where('depot_id',$id)->exists();
        abort_unless(($s['all']||in_array($id,$s['depots'])||$own)&&$s['role']!=='workshop',403);
        abort_if($active&&!$d->active,422,'This depot is inactive.');
        return $d;
    }

    public function driverIds(User $user,int $company,array $assigned=[]): ?array
    {
        $s=$this->scope($user,$company);
        if($s['all']&&$s['role']!=='workshop')return null;
        $depots=$s['depots'];
        if($s['role']==='driver')$depots=array_unique([...$depots,...Vehicle::where('company_id',$company)->where('assigned_driver_user_id',$user->id)->whereNotNull('depot_id')->pluck('depot_id')->all()]);
        $ids=$assigned;
        foreach(DB::table('fleet_member_scopes')->where('company_id',$company)->where('fleet_role','driver')->get(['user_id','depot_ids']) as $driver) {
            if(array_intersect(json_decode($driver->depot_ids,true)?:[],$depots))$ids[]=(int)$driver->user_id;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public function visibleVehicles(User $user)
    {
        $q=Vehicle::query();
        if($user->isSuperAdminOrMaster())return $q;
        $companyIds=app(CompanyTeamAccess::class)->companies($user)->pluck('id');
        $scopes=[];
        foreach($companyIds as $company) {
            try { $scopes[$company]=$this->scope($user,$company); }
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;}
        }
        return $q->where(function($x)use($user,$scopes,$companyIds){
            $x->where(fn($v)=>$v->whereNull('company_id')->where('owner_user_id',$user->id));
            foreach($scopes as $company=>$s) $x->orWhere(function($v)use($user,$company,$s){
                $v->where('company_id',$company);
                if($s['admin']||($s['all']&&$s['role']!=='workshop'))return;
                if($s['role']==='driver')$v->where('assigned_driver_user_id',$user->id);
                elseif($s['role']==='workshop')$v->whereIn('id',$s['vehicles']);
                else $v->whereIn('depot_id',$s['depots']);
            });
            // Explicit external access remains valid; it never elevates access to the owning company's depot.
            $x->orWhere(function($v)use($user,$companyIds){
                $v->where(fn($c)=>$c->whereNull('company_id')->orWhereNotIn('company_id',$companyIds))
                    ->where(fn($a)=>$a->where('owner_user_id',$user->id)->orWhere('assigned_driver_user_id',$user->id)
                        ->orWhereHas('permittedUsers',fn($u)=>$u->where('users.id',$user->id)->where('fleet_access.can_view',true))
                        ->orWhereHas('loads',fn($l)=>$l->whereIn('company_id',$companyIds)->where('status','in_delivery')));
            });
        });
    }
}
