<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Mail\CustomerFirstPasswordMail;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class CustomerController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->withAvg('reviews as average_rating', 'rating')->withCount('reviews');
    }

    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function relations(): array
    {
        return ['user.role'];
    }

    protected function relationsForRequest(Request $request): array
    {
        return $request->user()?->isSuperAdminOrMaster()
            ? [...$this->relations(), 'user.subscription.subscriptionPackage']
            : $this->relations();
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

    protected function applyOrdering(Builder $query, Request $request): void
    {
        $query
            ->orderByRaw('source_sort_order IS NULL')
            ->orderBy('source_sort_order')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function options(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'pageno' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Customer::query()->with('user');
        $this->applyFilters($query, $request);
        $this->applyOrdering($query, $request);

        $limit = (int) $request->query('limit', 20);
        $pageNumber = (int) $request->query('pageno', 1);
        $page = $query->paginate($limit, ['*'], 'page', $pageNumber);

        $options = collect($page->items())->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'text' => $customer->name ?: $customer->company_name ?: "Customer #{$customer->id}",
            'name' => $customer->name ?: $customer->company_name,
            'tax_number' => $customer->tax_number,
            'country_code' => $customer->country_code,
            'city' => $customer->city,
            'address' => $customer->billing_address,
            'source' => $customer->source,
        ])->values()->all();

        return $this->success($options, 'Customer options retrieved successfully.', [
            'current_page' => $page->currentPage(),
            'page_no' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'limit' => $page->perPage(),
            'total' => $page->total(),
            'has_more' => $page->hasMorePages(),
        ]);
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

    public function authorizeCustomer(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->is_active) {
            return response()->json([
                'message' => 'Customer is already authorized.',
                'data' => (new EntityResource($customer->load($this->relations())))->resolve($request),
                'meta' => [],
                'errors' => [],
            ], 422);
        }

        $data = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($customer->user_id),
            ],
        ]);

        $temporaryPassword = Str::password(14);

        $customer = DB::transaction(function () use ($customer, $data, $temporaryPassword): Customer {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $user = $lockedCustomer->user;

            if (! $user) {
                $user = User::query()->create([
                    'role_id' => Role::query()->where('name', 'user')->firstOrFail()->id,
                    'name' => $lockedCustomer->name ?: $lockedCustomer->company_name ?: $data['email'],
                    'email' => $data['email'],
                    'username' => $this->availableUsername($data['email'], $lockedCustomer->id),
                    'password' => $temporaryPassword,
                    'phone' => $lockedCustomer->phone,
                    'language' => $lockedCustomer->language ?: 'bs',
                    'country_code' => $lockedCustomer->country_code,
                    'is_active' => true,
                ]);
            } else {
                $user->update([
                    'email' => $data['email'],
                    'password' => $temporaryPassword,
                    'is_active' => true,
                ]);
            }

            $lockedCustomer->update([
                'user_id' => $user->id,
                'email' => $data['email'],
                'billing_email' => $lockedCustomer->billing_email ?: $data['email'],
                'status' => 'active',
                'profile_authorized_at' => now(),
            ]);

            return $lockedCustomer->load($this->relations());
        });

        $emailSent = true;
        try {
            Mail::to($data['email'])->send(new CustomerFirstPasswordMail(
                customerName: (string) ($customer->name ?: $customer->company_name ?: 'Customer'),
                username: (string) $customer->user->username,
                temporaryPassword: $temporaryPassword,
            ));
        } catch (Throwable $exception) {
            $emailSent = false;
            report($exception);
        }

        return $this->success(
            (new EntityResource($customer))->resolve($request),
            $emailSent
                ? 'Customer authorized and first password email sent.'
                : 'Customer authorized, but the first password email could not be sent.',
            ['email_sent' => $emailSent],
        );
    }

    private function availableUsername(string $email, int $customerId): string
    {
        $emailName = Str::before($email, '@');
        $base = Str::slug($emailName, '_') ?: 'customer';
        $base = substr($base, 0, 60);
        $username = $base;

        if (User::query()->where('username', $username)->exists()) {
            $username = "{$base}_{$customerId}";
        }

        $suffix = 2;
        while (User::query()->where('username', $username)->exists()) {
            $username = "{$base}_{$customerId}_{$suffix}";
            $suffix++;
        }

        return substr($username, 0, 80);
    }
}
