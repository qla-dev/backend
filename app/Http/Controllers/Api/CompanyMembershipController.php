<?php

namespace App\Http\Controllers\Api;

use App\Models\CompanyMembership;

class CompanyMembershipController extends CrudController
{
    protected function modelClass(): string
    {
        return CompanyMembership::class;
    }

    protected function relations(): array
    {
        return ['company', 'user.role', 'inviter'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => [$p, 'integer', 'exists:companies,id'], 'user_id' => [$p, 'integer', 'exists:users,id'], 'invited_by_user_id' => ['nullable', 'integer', 'exists:users,id'], 'company_role' => ['sometimes', 'string', 'max:80'], 'status' => ['sometimes', 'string', 'max:50'], 'joined_at' => ['nullable', 'date']];
    }
}
