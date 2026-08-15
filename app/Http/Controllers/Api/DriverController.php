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
    protected function modelClass(): string
    {
        return Driver::class;
    }

    protected function relations(): array
    {
        return ['user.role', 'primaryCompany'];
    }

    protected function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'user_id' => [$presence, 'integer', 'exists:users,id'],
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
        return ['license_number', 'license_country_code', 'availability_status'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('availability_status')) {
            $query->where('availability_status', $request->string('availability_status'));
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
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
            $user = User::query()->create([
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
            ]);

            $driver = Driver::query()->create([
                'user_id' => $user->id,
                'primary_company_id' => $data['primary_company_id'] ?? null,
                'license_number' => $data['license_number'],
                'license_country_code' => strtoupper($data['license_country_code']),
                'license_expires_at' => $data['license_expires_at'],
                'availability_status' => $data['availability_status'] ?? 'available',
            ]);

            if (! empty($data['primary_company_id'])) {
                $user->companies()->attach($data['primary_company_id'], [
                    'company_role' => 'driver',
                    'status' => 'active',
                    'invited_by_user_id' => $request->user()->id,
                    'joined_at' => now(),
                ]);
            }

            return $driver->load($this->relations());
        });

        return $this->success((new EntityResource($driver))->resolve($request), 'Driver account created.', status: 201);
    }
}
