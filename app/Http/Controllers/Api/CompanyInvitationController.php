<?php

namespace App\Http\Controllers\Api;

use App\Models\CompanyInvitation;
use Illuminate\Validation\Rule;

class CompanyInvitationController extends CrudController
{
    protected function modelClass(): string
    {
        return CompanyInvitation::class;
    }

    protected function relations(): array
    {
        return ['company', 'role', 'inviter', 'acceptedBy'];
    }

    protected function searchColumns(): array
    {
        return ['email', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => [$p, 'integer', 'exists:companies,id'], 'role_id' => [$p, 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('name', ['manager', 'dispatcher', 'customs_officer', 'finance', 'driver']))], 'invited_by_user_id' => [$p, 'integer', 'exists:users,id'], 'accepted_by_user_id' => ['nullable', 'integer', 'exists:users,id'], 'email' => [$p, 'email'], 'token' => [$p, 'string', 'size:64'], 'status' => ['sometimes', 'string', 'max:50'], 'expires_at' => [$p, 'date'], 'accepted_at' => ['nullable', 'date']];
    }
}
