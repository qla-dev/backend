<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends CrudController
{
    protected function modelClass(): string
    {
        return User::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['role_id' => [$p, 'integer', 'exists:roles,id'], 'name' => [$p, 'string', 'max:255'], 'email' => [$p, 'email', 'max:255'], 'username' => [$p, 'string', 'max:80'], 'password' => [$p, 'string', 'min:8'], 'phone' => ['nullable', 'string', 'max:50'], 'language' => ['nullable', 'string', 'max:5'], 'country_code' => ['nullable', 'string', 'size:2'], 'avatar_url' => ['nullable', 'url'], 'is_active' => ['sometimes', 'boolean']];
    }

    protected function relations(): array
    {
        return ['role', 'companies', 'driverProfile'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'email', 'username', 'phone', 'country_code'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('role')) {
            $query->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('name', $request->string('role')));
        }
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:80', 'unique:users,username'], 'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'], 'language' => ['nullable', 'string', 'max:5'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        $data['role_id'] = Role::query()->where('name', 'user')->firstOrFail()->id;
        $data['language'] = $data['language'] ?? 'bs';
        $data['country_code'] = isset($data['country_code']) ? strtoupper($data['country_code']) : null;
        $data['is_active'] = true;
        $data['email_verified_at'] = now();
        $user = User::query()->create($data)->load($this->relations());

        return $this->success((new EntityResource($user))->resolve($request), 'Customer account created.', status: 201);
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:80', 'unique:users,username'], 'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'], 'language' => ['nullable', 'string', 'max:5'],
            'country_code' => ['nullable', 'string', 'size:2'], 'primary_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'license_number' => ['required', 'string', 'max:120', 'unique:driver_profiles,license_number'],
            'license_country_code' => ['required', 'string', 'size:2'], 'license_expires_at' => ['required', 'date'],
            'availability_status' => ['nullable', 'in:available,on_load,off_duty,unavailable'],
        ]);

        $profile = DB::transaction(function () use ($data, $request) {
            $driverRole = Role::query()->where('name', 'driver')->firstOrFail();
            $user = User::query()->create([
                'role_id' => $driverRole->id, 'name' => $data['name'], 'email' => $data['email'],
                'username' => $data['username'], 'password' => $data['password'], 'phone' => $data['phone'] ?? null,
                'language' => $data['language'] ?? 'bs',
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'is_active' => true, 'email_verified_at' => now(),
            ]);
            $profile = DriverProfile::query()->create([
                'user_id' => $user->id, 'primary_company_id' => $data['primary_company_id'] ?? null,
                'license_number' => $data['license_number'],
                'license_country_code' => strtoupper($data['license_country_code']),
                'license_expires_at' => $data['license_expires_at'],
                'availability_status' => $data['availability_status'] ?? 'available',
            ]);
            if (! empty($data['primary_company_id'])) {
                $user->companies()->attach($data['primary_company_id'], [
                    'company_role' => 'driver', 'status' => 'active',
                    'invited_by_user_id' => $request->user()->id, 'joined_at' => now(),
                ]);
            }

            return $profile->load(['user.role', 'primaryCompany']);
        });

        return $this->success((new EntityResource($profile))->resolve($request), 'Driver account and profile created.', status: 201);
    }
}
