<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Company membership is independent of the user's platform/account role. */
final class CompanyTeamAccess
{
    public const ROLES = ['manager', 'dispatcher', 'customs_officer', 'finance', 'driver', 'warehouse', 'user'];
    public const DEPARTMENTS = ['management', 'sales', 'operations', 'customs', 'warehouse', 'finance', 'procurement'];
    public const SUBDEPARTMENTS = ['road', 'air', 'sea', 'rail', 'multimodal'];

    public function companies(User $user): Builder
    {
        $query = Company::query();
        if (!$user->isSuperAdminOrMaster()) {
            $query->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhereHas('users',
                fn ($u) => $u->where('users.id', $user->id)->where('company_user.status', 'active')));
        }
        return $query;
    }

    public function role(User $user, int $company): ?string
    {
        if ($user->isSuperAdminOrMaster()) return $user->role->name;
        return $this->companyRole($user, $company);
    }

    public function companyRole(User $user, int $company): ?string
    {
        if ((int) DB::table('companies')->where('id', $company)->value('owner_user_id') === (int) $user->id) return 'company';
        $member = DB::table('company_user')->where('company_id', $company)->where('user_id', $user->id)->where('status', 'active')->first();
        if (!$member) return null;
        // Compatibility while deploying the additive migration; never mutate users.role_id.
        if (!Schema::hasColumn('company_user', 'company_role_id')) return in_array($user->role?->name, self::ROLES, true) ? $user->role->name : 'user';
        return $member->company_role_id ? DB::table('roles')->where('id', $member->company_role_id)->whereIn('name', self::ROLES)->value('name') : 'user';
    }

    public function manages(User $user, int $company): bool
    {
        return in_array($this->role($user, $company), ['superadmin', 'master', 'company', 'manager'], true);
    }

    public function authorize(User $user, int $company, bool $manage = false): void
    {
        abort_unless($manage ? $this->manages($user, $company) : $this->role($user, $company) !== null, 403);
    }

    public function organization(object $membership): array
    {
        $decode = fn ($v) => is_array($v) ? $v : (json_decode($v ?? '[]', true) ?: []);
        $legacy = $decode($membership->departments ?? []);
        $business = isset($membership->business_departments) ? $decode($membership->business_departments) : array_values(array_intersect($legacy, ['customs', 'warehouse', 'finance']));
        if (!isset($membership->business_departments)) {
            if (in_array('accounting', $legacy, true)) $business[] = 'finance';
            if (array_intersect($legacy, self::SUBDEPARTMENTS)) $business[] = 'operations';
        }
        return ['business_departments' => array_values(array_unique($business)),
            'subdepartments' => isset($membership->subdepartments) ? $decode($membership->subdepartments) : array_values(array_intersect($legacy, self::SUBDEPARTMENTS))];
    }
}
