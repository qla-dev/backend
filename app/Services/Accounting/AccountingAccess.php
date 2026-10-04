<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountingAccess
{
    public const ABILITIES = ['view', 'prepare', 'approve', 'post', 'correct', 'payments', 'periods', 'rules', 'setup'];

    public function member(User $user, int $companyId): Company
    {
        $company = Company::findOrFail($companyId);
        abort_unless((int) $company->owner_user_id === (int) $user->id || DB::table('company_user')
            ->where('company_id', $companyId)->where('user_id', $user->id)->where('status', 'active')->exists(), 403);

        return $company;
    }

    public function abilities(User $user, int $companyId): array
    {
        $this->member($user, $companyId);

        return json_decode(DB::table('accounting_permissions')->where('company_id', $companyId)
            ->where('user_id', $user->id)->value('abilities') ?? '[]', true) ?: [];
    }

    public function authorize(User $user, int $companyId, string $ability): void
    {
        abort_unless(in_array($ability, $this->abilities($user, $companyId), true), 403, 'Accounting permission required: '.$ability);
    }
}
