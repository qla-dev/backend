<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Company;
use App\Models\Load;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoadController extends CrudController
{
    public function publicIndex(): JsonResponse
    {
        $loads = Load::query()
            ->with([
                'company:id,name',
                'stops' => fn ($query) => $query
                    ->select(['id', 'load_id', 'type', 'position', 'city', 'country_code', 'window_starts_at', 'window_ends_at'])
                    ->orderBy('position'),
            ])
            ->where('status', 'posted')
            ->latest('published_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Load $load): array => [
                'id' => $load->id,
                'title' => $load->title,
                'status' => $load->status,
                'cargo_type' => $load->cargo_type,
                'goods_type' => $load->goods_type,
                'weight_kg' => $load->weight_kg,
                'currency' => $load->currency,
                'budget' => $load->budget,
                'payment_terms' => $load->payment_terms,
                'payment_due_days' => $load->payment_due_days,
                'transport_type' => $load->transport_type,
                'is_negotiable' => $load->is_negotiable,
                'published_at' => $load->published_at,
                'created_at' => $load->created_at,
                'company' => $load->company ? ['name' => $load->company->name] : null,
                'stops' => $load->stops->map(fn ($stop): array => [
                    'type' => $stop->type,
                    'position' => $stop->position,
                    'city' => $stop->city,
                    'country_code' => $stop->country_code,
                    'window_starts_at' => $stop->window_starts_at,
                    'window_ends_at' => $stop->window_ends_at,
                ])->values(),
            ])
            ->values();

        return $this->success($loads, 'Public loads retrieved successfully.', [
            'total' => $loads->count(),
        ]);
    }

    protected function modelClass(): string
    {
        return Load::class;
    }

    protected function relations(): array
    {
        return ['customer.role', 'consignee.user.role', 'company', 'assignedDriver.driver', 'vehicle', 'stops', 'offers', 'shipment.events', 'routes.stops', 'notes.author', 'documents'];
    }

    protected function searchColumns(): array
    {
        return ['title', 'status', 'cargo_type', 'goods_type'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $status = trim((string) $request->query('status', ''));

        if ($status !== '') {
            $query->where('status', $status);
        }

        $user = $request->user();
        $role = $user?->role?->name;

        // The freight marketplace (status=posted) is a shared pool every role browses to find
        // or offer work — only restrict visibility once a load has moved past that public
        // listing stage into an actual booking/relationship.
        if (! $user || $role === 'superadmin' || $status === 'posted') {
            return;
        }

        if ($role === 'user') {
            $query->where('customer_user_id', $user->id);

            return;
        }

        if ($role === 'driver') {
            $query->where('assigned_driver_user_id', $user->id);

            return;
        }

        if (in_array($role, ['company', 'finance'], true)) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->where(function (Builder $scope) use ($user, $companyIds): void {
                $scope->where('customer_user_id', $user->id)->orWhereIn('company_id', $companyIds);
            });
        }
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return [
            'customer_user_id' => ['sometimes', 'integer', 'exists:users,id'], 'consignee_customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'title' => [$p, 'string', 'max:255'], 'booking_reference' => ['nullable', 'string', 'max:160'], 'insurance' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:120'], 'freight_mode' => ['nullable', 'string', 'max:120'], 'subdepartment' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(Load::STATUSES)], 'transport_type' => ['sometimes', 'in:road,air,sea'],
            'cargo_type' => [$p, 'string', 'max:100'], 'goods_type' => ['nullable', 'string', 'max:100'], 'weight_kg' => [$p, 'numeric', 'min:0.01'],
            'length_m' => ['nullable', 'numeric', 'min:0'], 'width_m' => ['nullable', 'numeric', 'min:0'], 'height_m' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'], 'pallets' => ['nullable', 'integer', 'min:0'], 'quantity_measure' => ['nullable', 'string', 'max:255'],
            'teu' => ['nullable', 'string', 'max:80'], 'container_types' => ['nullable', 'string', 'max:255'], 'container_number' => ['nullable', 'string', 'max:255'],
            'etd_at' => ['nullable', 'date'], 'atd_at' => ['nullable', 'date'], 'shipper_name' => ['nullable', 'string', 'max:255'],
            'mediator' => ['nullable', 'string', 'max:255'], 'incoterms' => ['nullable', 'string', 'max:80'],
            'price_insurance' => ['nullable', 'string'], 'profit_loss' => ['nullable', 'string'], 'temperature_min' => ['nullable', 'numeric'],
            'temperature_max' => ['nullable', 'numeric'], 'declared_value' => ['nullable', 'numeric', 'min:0'], 'shipment_value_currency' => ['nullable', 'string', 'size:3'], 'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'], 'payment_terms' => ['sometimes', 'string', 'max:50'], 'payment_due_days' => ['nullable', 'integer', 'min:0'],
            'is_negotiable' => ['sometimes', 'boolean'],
            'is_fragile' => ['sometimes', 'boolean'], 'requires_adr' => ['sometimes', 'boolean'], 'requires_tail_lift' => ['sometimes', 'boolean'],
            'must_be_trackable' => ['sometimes', 'boolean'], 'is_urgent' => ['sometimes', 'boolean'], 'loading_methods' => ['nullable', 'array'],
            'toll_roads_included' => ['sometimes', 'boolean'], 'ferry_included' => ['sometimes', 'boolean'], 'cmr_required' => ['sometimes', 'boolean'],
            'pallet_exchange_required' => ['sometimes', 'boolean'], 'customs_required' => ['sometimes', 'boolean'],
            'vehicle_type' => ['nullable', 'string', 'max:100'], 'transport_mode' => ['nullable', 'string', 'max:120'], 'special_requirements' => ['nullable', 'array'], 'special_requirements.*' => ['string', 'max:255'], 'characteristics' => ['nullable', 'string', 'max:255'], 'delivery_proof' => ['nullable', 'string', 'max:30'],
            'body_types' => ['nullable', 'array'], 'contact' => ['nullable', 'array'], 'notes' => ['nullable', 'string'], 'internal_comments' => ['nullable', 'string'],
            'external_comments' => ['nullable', 'string'], 'published_at' => ['nullable', 'date'], 'completed_at' => ['nullable', 'date'],
            'stops' => ['sometimes', 'array', 'min:2'], 'stops.*.type' => ['required_with:stops', 'in:pickup,waypoint,delivery'],
            'stops.*.position' => ['required_with:stops', 'integer', 'min:1'], 'stops.*.place_type' => ['nullable', 'string', 'max:100'],
            'stops.*.city' => ['required_with:stops', 'string', 'max:120'], 'stops.*.country_code' => ['required_with:stops', 'string', 'size:2'],
            'stops.*.address' => ['nullable', 'string', 'max:255'], 'stops.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'stops.*.longitude' => ['nullable', 'numeric', 'between:-180,180'], 'stops.*.window_starts_at' => ['nullable', 'date'],
            'stops.*.window_ends_at' => ['nullable', 'date'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $stops = $data['stops'] ?? [];
        unset($data['stops']);
        if ($request->user()?->role?->name === 'user') {
            $data['customer_user_id'] = $request->user()->id;
        }
        if ($request->user()?->role?->name === 'superadmin') {
            $demoCompanyId = Company::query()
                ->where('slug', 'smartfreight-logistics-hub')
                ->value('id');

            if ($demoCompanyId) {
                $data['company_id'] = $demoCompanyId;
            }
        }
        $data['customer_user_id'] = $data['customer_user_id'] ?? $request->user()->id;
        $data['status'] = $data['status'] ?? 'pending';
        $data['public_id'] = (string) Str::uuid();
        $load = DB::transaction(function () use ($data, $stops) {
            $load = Load::query()->create($data);
            if ($stops !== []) {
                $load->stops()->createMany($stops);
            }

            return $load;
        });
        $load->load($this->relations());

        return $this->success((new EntityResource($load))->resolve($request), 'Load created successfully.', status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $hasStops = array_key_exists('stops', $data);
        $stops = $data['stops'] ?? [];
        unset($data['stops']);
        $load = DB::transaction(function () use ($request, $id, $data, $hasStops, $stops) {
            $load = Load::query()->findOrFail($id);
            if (array_key_exists('status', $data) && $data['status'] !== $load->status) {
                abort_unless($request->user()?->role?->name === 'superadmin', 403, 'Only a superadmin can change a load status.');
            }
            $load->update($data);
            if ($hasStops) {
                $load->stops()->delete();
                $load->stops()->createMany($stops);
            }

            return $load;
        });
        $load->load($this->relations());

        return $this->success((new EntityResource($load))->resolve($request), 'Load updated successfully.');
    }

    public function updateStatus(Request $request, Load $load): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Load::STATUSES)],
        ]);

        $load->update(['status' => $data['status']]);
        $load->load($this->relations());

        return $this->success((new EntityResource($load))->resolve($request), 'Load status updated successfully.');
    }

    public function book(Request $request, Load $load): JsonResponse
    {
        $role = $request->user()?->role?->name;
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $updated = DB::transaction(function () use ($request, $load, $role, $data) {
            $load = Load::query()->lockForUpdate()->findOrFail($load->id);
            abort_if($load->is_negotiable, 422, 'This load accepts offers instead of direct booking.');
            abort_unless($load->status === 'posted' && ! $load->assigned_driver_user_id, 409, 'This load is no longer available.');

            $user = $request->user();
            $companyId = null;
            $driverUserId = null;

            if ($role === 'driver') {
                // Unchanged: a driver books for themselves immediately, same as before.
                $companyId = $load->company_id ?? $user->driver?->primary_company_id;
                $driverUserId = $user->id;
            } elseif ($role === 'company') {
                // A company claims the load for itself now; assigning a driver from their team
                // is optional at booking time and can be done later instead.
                $myCompanyIds = $user->companies()->pluck('companies.id');
                abort_if($myCompanyIds->isEmpty(), 422, 'You are not linked to a company.');
                $companyId = $data['company_id'] ?? $myCompanyIds->first();
                abort_unless($myCompanyIds->contains($companyId), 403, 'You can only book for your own company.');
                $driverUserId = $this->resolveCompanyDriver($companyId, $data['driver_user_id'] ?? null);
            } elseif ($role === 'superadmin') {
                // Superadmin can dedicate the load to any company and/or any driver, or neither.
                $companyId = $data['company_id'] ?? null;
                $driverUserId = $companyId
                    ? $this->resolveCompanyDriver($companyId, $data['driver_user_id'] ?? null)
                    : ($data['driver_user_id'] ?? null);
            } else {
                abort(403, 'You are not allowed to book loads.');
            }

            $load->update([
                'assigned_driver_user_id' => $driverUserId,
                'company_id' => $companyId,
                'status' => 'sent',
            ]);

            return $load;
        });
        $updated->load($this->relations());

        return $this->success((new EntityResource($updated))->resolve($request), 'Load booked successfully.');
    }

    private function resolveCompanyDriver(int $companyId, ?int $driverUserId): ?int
    {
        if (! $driverUserId) {
            return null;
        }

        $company = Company::query()->findOrFail($companyId);
        abort_unless($company->users()->where('users.id', $driverUserId)->exists(), 422, 'The selected driver is not part of this company.');

        return $driverUserId;
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'loads' => ['required', 'array', 'min:1', 'max:50'],
        ]);

        $loads = DB::transaction(function () use ($payload, $request): array {
            $created = [];
            foreach ($payload['loads'] as $index => $loadPayload) {
                $data = validator(
                    is_array($loadPayload) ? $loadPayload : [],
                    $this->rules(),
                )->validate();
                $stops = $data['stops'] ?? [];
                unset($data['stops']);
                if ($request->user()?->role?->name === 'user') {
                    $data['customer_user_id'] = $request->user()->id;
                }
                $data['customer_user_id'] = $data['customer_user_id'] ?? $request->user()->id;
                $data['status'] = $data['status'] ?? 'pending';
                $data['public_id'] = (string) Str::uuid();

                $load = Load::query()->create($data);
                if ($stops !== []) {
                    $load->stops()->createMany($stops);
                }
                $created[] = $load;
            }

            return $created;
        });

        $resources = collect($loads)->map(fn (Load $load) => (new EntityResource($load->load($this->relations())))->resolve($request));

        return $this->success($resources->all(), 'Loads created successfully.', status: 201);
    }
}
