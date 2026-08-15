<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends CrudController
{
    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function relations(): array
    {
        return ['user.role'];
    }

    protected function rules(bool $updating = false): array
    {
        return [
            'customer_type' => ['sometimes', 'in:private,business'],
            'status' => ['sometimes', 'in:active,inactive,pending'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100', 'unique:customers,tax_number'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'profile_authorized_at' => ['nullable', 'date'],
        ];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $customerQuery) use ($search): void {
                $customerQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('country_code', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('country_code', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
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
            'customer_type' => ['nullable', 'in:private,business'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100', 'unique:customers,tax_number'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $customer = DB::transaction(function () use ($data): Customer {
            $user = ! empty($data['password']) ? User::query()->create([
                'role_id' => Role::query()->where('name', 'user')->firstOrFail()->id,
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

            return Customer::query()->create([
                'user_id' => $user?->id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
                'customer_type' => $data['customer_type'] ?? 'business',
                'status' => 'active',
                'company_name' => $data['company_name'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'billing_address' => $data['billing_address'] ?? null,
                'city' => $data['city'] ?? null,
                'profile_authorized_at' => $user ? now() : null,
            ])->load($this->relations());
        });

        return $this->success((new EntityResource($customer))->resolve($request), 'Customer created.', status: 201);
    }
}
