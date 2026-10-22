<?php
namespace App\Services\Ops;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
final class OpsDepartments {
    public const ALL=['road','air','sea','customs','rail','multimodal','warehouse','finance','accounting'];
    public function forUser(int $company,int $user): array {
        if(!Schema::hasColumn('company_user','departments'))return [];
        return json_decode(DB::table('company_user')->where('company_id',$company)->where('user_id',$user)->where('status','active')->value('departments')??'[]',true)?:[];
    }
    public function manages(User $user,int $company): bool {
        return $user->isSuperAdminOrMaster()||(int)DB::table('companies')->where('id',$company)->value('owner_user_id')===(int)$user->id
            ||($user->role?->name==='manager'&&DB::table('company_user')->where('company_id',$company)->where('user_id',$user->id)->where('status','active')->exists());
    }
}
