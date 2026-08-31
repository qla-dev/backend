<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->withAvg('reviews as average_rating', 'rating')->withCount('reviews');
    }

    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        $companyId = $updating ? (int) request()->route('company') : null;

        return ['owner_user_id' => [$p, 'integer', 'exists:users,id', Rule::unique('companies', 'owner_user_id')->ignore($companyId)], 'name' => [$p, 'string', 'max:255'], 'slug' => [$p, 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:50'], 'tax_number' => ['nullable', 'string', 'max:100'], 'vat_number' => ['nullable', 'string', 'max:100'], 'registration_number' => ['nullable', 'string', 'max:100'], 'country_code' => [$p, 'string', 'size:2'], 'city' => ['nullable', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:255'], 'website' => ['nullable', 'url', 'max:255'], 'logo_url' => ['nullable', 'url', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'], 'warehouse_first' => ['sometimes', 'boolean'], 'plan' => ['sometimes', 'string', 'max:50'], 'status' => ['sometimes', 'string', 'max:50'], 'verified_at' => ['nullable', 'date']];
    }

    protected function relations(): array
    {
        return ['owner.subscription.subscriptionPackage', 'users', 'vehicles', 'warehouses'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if ($request->has('warehouse_first')) {
            $query->where('warehouse_first', $request->boolean('warehouse_first'));
        }
    }

    protected function searchColumns(): array
    {
        return ['name', 'email', 'tax_number', 'registration_number'];
    }

    public function onboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'plan' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner_username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'owner_password' => ['required', 'string', 'min:8'],
            'owner_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $company = DB::transaction(function () use ($data, $request) {
            $role = Role::query()->where('name', 'company')->firstOrFail();
            $owner = User::query()->create([
                'role_id' => $role->id, 'name' => $data['owner_name'], 'email' => $data['owner_email'],
                'username' => $data['owner_username'], 'password' => $data['owner_password'],
                'phone' => $data['owner_phone'] ?? null, 'language' => 'bs',
                'country_code' => strtoupper($data['country_code']), 'is_active' => true, 'email_verified_at' => now(),
            ]);
            $baseSlug = Str::slug($data['company_name']) ?: 'company';
            $slug = $baseSlug;
            $suffix = 2;
            while (Company::query()->where('slug', $slug)->exists()) $slug = $baseSlug.'-'.$suffix++;
            $company = Company::query()->create([
                'owner_user_id' => $owner->id, 'name' => $data['company_name'], 'slug' => $slug,
                'email' => $data['company_email'] ?? null, 'phone' => $data['company_phone'] ?? null,
                'tax_number' => $data['tax_number'] ?? null, 'registration_number' => $data['registration_number'] ?? null,
                'country_code' => strtoupper($data['country_code']), 'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null, 'plan' => $data['plan'] ?? 'starter',
                'status' => $data['status'] ?? 'pending', 'warehouse_first' => false,
            ]);
            $company->users()->attach($owner->id, ['status' => 'active', 'invited_by_user_id' => $request->user()->id, 'joined_at' => now()]);
            return $company->load($this->relations());
        });

        return $this->success((new EntityResource($company))->resolve($request), 'Company and owner account created.', status: 201);
    }
}
