<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->withAvg('reviews as average_rating', 'rating')->withCount('reviews');
    }

    protected function modelClass(): string
    {
        return Driver::class;
    }

    protected function relations(): array
    {
        return ['user.role', 'primaryCompany'];
    }

    protected function relationsForRequest(Request $request): array
    {
        return $request->user()?->isSuperAdminOrMaster()
            ? [...$this->relations(), 'user.subscription.subscriptionPackage']
            : $this->relations();
    }

    protected function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'profile_authorized_at' => ['nullable', 'date'],
            'primary_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'license_number' => [$presence, 'string', 'max:120', 'unique:drivers,license_number'],
            'license_country_code' => [$presence, 'string', 'size:2'],
            'license_expires_at' => [$presence, 'date'],
            'availability_status' => ['sometimes', 'in:available,on_load,off_duty,unavailable'],
            'rating' => ['sometimes', 'numeric', 'between:0,5'],
            'completed_trips' => ['sometimes', 'integer', 'min:0'],
            'certifications' => ['nullable', 'array'],
        ];
    }

    protected function searchColumns(): array
    {
        return ['name', 'email', 'phone', 'license_number', 'license_country_code', 'availability_status'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('availability_status')) {
            $query->where('availability_status', $request->string('availability_status'));
        }

        $user = $request->user();
        if (in_array($user?->role?->name, ['company', 'manager', 'dispatcher', 'customs_officer'], true)) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->whereIn('primary_company_id', $companyIds);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_with:password', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:80', 'required_with:password', 'unique:users,username'],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:5'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'primary_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'license_number' => ['required', 'string', 'max:120', 'unique:drivers,license_number'],
            'license_country_code' => ['required', 'string', 'size:2'],
            'license_expires_at' => ['required', 'date'],
            'availability_status' => ['nullable', 'in:available,on_load,off_duty,unavailable'],
        ]);

        $driver = DB::transaction(function () use ($data, $request): Driver {
            $user = ! empty($data['password']) ? User::query()->create([
                'role_id' => Role::query()->where('name', 'driver')->firstOrFail()->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'language' => $data['language'] ?? 'bs',
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]) : null;

            $driver = Driver::query()->create([
                'user_id' => $user?->id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'profile_authorized_at' => $user ? now() : null,
                'primary_company_id' => $data['primary_company_id'] ?? null,
                'license_number' => $data['license_number'],
                'license_country_code' => strtoupper($data['license_country_code']),
                'license_expires_at' => $data['license_expires_at'],
                'availability_status' => $data['availability_status'] ?? 'available',
            ]);

            if ($user && ! empty($data['primary_company_id'])) {
                $user->companies()->attach($data['primary_company_id'], [
                    'status' => 'active',
                    'invited_by_user_id' => $request->user()->id,
                    'joined_at' => now(),
                ]);
            }

            return $driver->load($this->relations());
        });

        return $this->success((new EntityResource($driver))->resolve($request), 'Driver created.', status: 201);
    }
}
