<?php

namespace App\Http\Controllers\Api;

use App\Models\CompanyInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyInvitationController extends CrudController
{
    public function availableUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'between:1,25'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $users = User::query()
            ->with('role:id,name,label')
            ->where('is_active', true)
            ->whereDoesntHave('companies')
            ->whereHas('role', fn ($query) => $query->whereNotIn('name', [...Role::PROTECTED_NAMES, 'system', 'guest']))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($scope) use ($search): void {
                    $scope->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit((int) ($data['limit'] ?? 10))
            ->get(['id', 'role_id', 'name', 'email', 'username']);

        return response()->json(['message' => 'Available users retrieved.', 'data' => $users, 'meta' => [], 'errors' => []]);
    }

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
