<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Load;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoadController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->withAvg('reviews as average_rating', 'rating')->withCount('reviews');
    }

    public function profileStatusCounts(Request $request): JsonResponse
    {
        $request->query->remove('status');
        $request->query->remove('statuses');
        $query = Load::query();
        $this->applyFilters($query, $request);
        $counts = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->map(fn (Load $load): array => ['status' => $load->status, 'count' => (int) $load->aggregate])
            ->values();

        return $this->success($counts, 'Profile load status counts retrieved successfully.');
    }

    public function trackingStatusCounts(Request $request): JsonResponse
    {
        $request->query->set('tracking', 'true');
        $request->query->remove('status');
        $request->query->remove('statuses');
        $query = Load::query();
        $this->applyFilters($query, $request);
        $counts = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->map(fn (Load $load): array => ['status' => $load->status, 'count' => (int) $load->aggregate])
            ->values();

        return $this->success($counts, 'Tracking status counts retrieved successfully.');
    }

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
                'hs_codes' => $load->hs_codes,
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

    protected function relationsForRequest(Request $request): array
    {
        if ($request->boolean('tracking')) {
            return ['consignee', 'company', 'stops', 'shipment'];
        }

        return parent::relationsForRequest($request);
    }

    protected function searchColumns(): array
    {
        return ['title', 'status', 'cargo_type', 'goods_type', 'storage_type', 'warehouse_city'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $request->validate([
            'tracking' => ['sometimes', Rule::in(['true', 'false', '1', '0', 1, 0, true, false])],
            'tracking_search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Load::STATUSES)],
            'statuses' => ['sometimes', 'string', 'max:500'],
            'transport_types' => ['sometimes', 'string', 'max:500'],
            'services' => ['sometimes', 'string', 'max:500'],
            'partner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'equipment' => ['sometimes', 'nullable', 'string', 'max:100'],
            'characteristics' => ['sometimes', 'string', 'max:1000'],
            'incoterms' => ['sometimes', 'string', 'max:500'],
            'currencies' => ['sometimes', 'string', 'max:100'],
            'tracking_date_from' => ['sometimes', 'date'],
            'tracking_date_to' => ['sometimes', 'date', 'after_or_equal:tracking_date_from'],
            'tracking_requires_adr' => ['sometimes', Rule::in(['true', 'false', '1', '0', 1, 0, true, false])],
            'tracking_is_urgent' => ['sometimes', Rule::in(['true', 'false', '1', '0', 1, 0, true, false])],
            'for_storage' => ['sometimes', Rule::in(['true', 'false', '1', '0', 1, 0, true, false])],
            'sort' => ['sometimes', Rule::in(['price_asc', 'price_desc', 'date_asc', 'date_desc'])],
            'origin' => ['sometimes', 'nullable', 'string', 'max:255'], 'destination' => ['sometimes', 'nullable', 'string', 'max:255'],
            'warehouse_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'budget_min' => ['sometimes', 'numeric', 'min:0'], 'budget_max' => ['sometimes', 'numeric', 'min:0'],
            'weight_min' => ['sometimes', 'numeric', 'min:0'], 'weight_max' => ['sometimes', 'numeric', 'min:0'],
            'length_min' => ['sometimes', 'numeric', 'min:0'], 'length_max' => ['sometimes', 'numeric', 'min:0'],
            'width_min' => ['sometimes', 'numeric', 'min:0'], 'width_max' => ['sometimes', 'numeric', 'min:0'],
            'height_min' => ['sometimes', 'numeric', 'min:0'], 'height_max' => ['sometimes', 'numeric', 'min:0'],
            'temperature_min' => ['sometimes', 'numeric'], 'temperature_max' => ['sometimes', 'numeric'],
            'cargo_value_min' => ['sometimes', 'numeric', 'min:0'], 'cargo_value_max' => ['sometimes', 'numeric', 'min:0'],
            'transit_days_min' => ['sometimes', 'integer', 'min:0'], 'transit_days_max' => ['sometimes', 'integer', 'min:0'],
            'pallets_min' => ['sometimes', 'integer', 'min:0'], 'pallets_max' => ['sometimes', 'integer', 'min:0'],
            'volume_min' => ['sometimes', 'numeric', 'min:0'], 'volume_max' => ['sometimes', 'numeric', 'min:0'],
            'storage_start_from' => ['sometimes', 'date'], 'storage_start_to' => ['sometimes', 'date', 'after_or_equal:storage_start_from'],
            'goods_types' => ['sometimes', 'string', 'max:1000'], 'payment_terms' => ['sometimes', 'string', 'max:500'],
            'storage_types' => ['sometimes', 'string', 'max:1000'], 'price_terms' => ['sometimes', 'string', 'max:100'],
            'adr_classes' => ['sometimes', 'string', 'max:500'], 'sensitivity' => ['sometimes', 'string', 'max:500'],
            'urgency' => ['sometimes', 'string', 'max:500'], 'loading_methods' => ['sometimes', 'string', 'max:500'],
            'requirements' => ['sometimes', 'string', 'max:500'],
            'profile_customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'profile_company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'profile_driver_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            // Freight-exchange filter bar: route, cargo nature, equipment, stop windows and assignment.
            'pickup_country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pickup_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'delivery_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cargo_flags' => ['sometimes', 'string', 'max:500'],
            'equipment_types' => ['sometimes', 'string', 'max:500'],
            'pickup_date_from' => ['sometimes', 'date'],
            'pickup_date_to' => ['sometimes', 'date', 'after_or_equal:pickup_date_from'],
            'delivery_date_from' => ['sometimes', 'date'],
            'delivery_date_to' => ['sometimes', 'date', 'after_or_equal:delivery_date_from'],
            'assignment' => ['sometimes', 'string', 'max:200'],
        ]);
        $status = trim((string) $request->query('status', ''));

        if ($status !== '') {
            $query->where('status', $status);
        } elseif ($request->boolean('tracking')) {
            $query->where('status', '!=', 'posted');
        }

        $this->applyWhereIn($query, 'status', $request->query('statuses'));
        $this->applyWhereIn($query, 'transport_type', $request->query('transport_types'));
        $this->applyWhereIn($query, 'cargo_type', $request->query('services'));
        $this->applyWhereIn($query, 'incoterms', $request->query('incoterms'));
        $this->applyWhereIn($query, 'currency', $request->query('currencies'));

        if ($request->filled('profile_customer_id')) {
            $customer = Customer::query()->select(['id', 'user_id'])->findOrFail($request->integer('profile_customer_id'));
            $query->where(function (Builder $customerLoads) use ($customer): void {
                $customerLoads->where('consignee_customer_id', $customer->id);
                if ($customer->user_id) {
                    $customerLoads->orWhere('customer_user_id', $customer->user_id);
                }
            });
        }
        if ($request->filled('profile_company_id')) {
            $query->where('company_id', $request->integer('profile_company_id'));
        }
        if ($request->filled('profile_driver_user_id')) {
            $query->where('assigned_driver_user_id', $request->integer('profile_driver_user_id'));
        }

        if ($trackingSearch = trim((string) $request->query('tracking_search', ''))) {
            $query->where(function (Builder $search) use ($trackingSearch): void {
                $search->where('title', 'like', "%{$trackingSearch}%")
                    ->orWhere('booking_reference', 'like', "%{$trackingSearch}%")
                    ->orWhereHas('shipment', fn (Builder $shipment) => $shipment
                        ->where('tracking_number', 'like', "%{$trackingSearch}%")
                        ->orWhere('carrier', 'like', "%{$trackingSearch}%"))
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', "%{$trackingSearch}%"))
                    ->orWhereHas('stops', fn (Builder $stops) => $stops->where('city', 'like', "%{$trackingSearch}%"));
            });
        }

        if ($partner = trim((string) $request->query('partner', ''))) {
            $query->where(function (Builder $partners) use ($partner): void {
                $partners->whereHas('shipment', fn (Builder $shipment) => $shipment->where('carrier', 'like', "%{$partner}%"))
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', "%{$partner}%"));
            });
        }
        if ($equipment = trim((string) $request->query('equipment', ''))) {
            $query->where('vehicle_type', $equipment);
        }
        foreach ($this->csv($request->query('characteristics')) as $characteristic) {
            $query->whereJsonContains('characteristics', $characteristic);
        }
        if ($request->filled('tracking_date_from')) $query->whereDate('updated_at', '>=', $request->query('tracking_date_from'));
        if ($request->filled('tracking_date_to')) $query->whereDate('updated_at', '<=', $request->query('tracking_date_to'));
        if ($request->has('tracking_requires_adr')) $query->where('requires_adr', $request->boolean('tracking_requires_adr'));
        if ($request->has('tracking_is_urgent')) $query->where('is_urgent', $request->boolean('tracking_is_urgent'));

        if ($request->has('for_storage')) {
            $query->where('for_storage', $request->boolean('for_storage'));
        }

        $this->applyLocationFilter($query, 'pickup', trim((string) $request->query('origin', '')));
        $this->applyLocationFilter($query, 'delivery', trim((string) $request->query('destination', '')));
        if ($warehouseLocation = trim((string) $request->query('warehouse_location', ''))) {
            $query->where(function (Builder $location) use ($warehouseLocation): void {
                $location->where('warehouse_city', 'like', "%{$warehouseLocation}%")
                    ->orWhere('warehouse_country_code', 'like', "%{$warehouseLocation}%")
                    ->orWhere('warehouse_address', 'like', "%{$warehouseLocation}%");
            });
        }

        foreach ([
            'budget' => 'budget', 'weight' => 'weight_kg', 'length' => 'length_m', 'width' => 'width_m',
            'height' => 'height_m', 'cargo_value' => 'declared_value', 'transit_days' => 'transit_days',
            'pallets' => 'pallets', 'volume' => 'volume_m3',
        ] as $parameter => $column) {
            $this->applyNumericRange($query, $request, $parameter, $column);
        }
        if ($request->filled('temperature_min')) $query->where('temperature_max', '>=', $request->query('temperature_min'));
        if ($request->filled('temperature_max')) $query->where('temperature_min', '<=', $request->query('temperature_max'));
        if ($request->filled('storage_start_from')) $query->whereDate('storage_start_date', '>=', $request->query('storage_start_from'));
        if ($request->filled('storage_start_to')) $query->whereDate('storage_start_date', '<=', $request->query('storage_start_to'));

        $this->applyWhereIn($query, 'goods_type', $request->query('goods_types'));
        $this->applyWhereIn($query, 'payment_terms', $request->query('payment_terms'));
        $this->applyWhereIn($query, 'storage_type', $request->query('storage_types'));
        if ($request->filled('price_terms')) {
            $terms = $this->csv($request->query('price_terms'));
            $query->where(function (Builder $scope) use ($terms): void {
                if (in_array('negotiable', $terms, true)) $scope->orWhere('is_negotiable', true);
                if (in_array('fixed', $terms, true)) $scope->orWhere('is_negotiable', false);
            });
        }
        if ($request->filled('sensitivity')) $query->where('is_fragile', true);
        if ($request->filled('adr_classes')) {
            $classes = $this->csv($request->query('adr_classes'));
            if ($classes === ['None']) $query->where('requires_adr', false);
            elseif (! in_array('None', $classes, true)) $query->where('requires_adr', true);
        }
        if ($request->filled('urgency')) {
            $urgency = $this->csv($request->query('urgency'));
            $query->whereIn('is_urgent', array_values(array_unique(array_map(fn (string $value): bool => strtolower($value) === 'express', $urgency))));
        }
        foreach ($this->csv($request->query('loading_methods')) as $method) {
            $query->whereJsonContains('loading_methods', $method);
        }
        foreach ($this->csv($request->query('requirements')) as $requirement) {
            // Special requirements are AND-ed: asking for CMR + ADR means both must hold.
            $column = match ($requirement) {
                'customs_bonded' => 'requires_customs_bonded', 'racking' => 'requires_racking',
                'insurance' => 'insurance_required', 'security' => 'requires_security',
                'cmr' => 'cmr_required', 'adr' => 'requires_adr', 'customs' => 'customs_required',
                'tail_lift' => 'requires_tail_lift', 'tracking' => 'must_be_trackable',
                'pallet_exchange' => 'pallet_exchange_required', 'certification' => 'certification_required',
                'inspection' => 'inspection_services_required',
                default => null,
            };
            if ($column) {
                $query->where($column, true);
                continue;
            }
            if ($requirement === 'forklift') {
                $query->whereJsonContains('loading_methods', 'Forklift');
            } elseif ($requirement === 'temperature_control') {
                $query->where(fn (Builder $scope) => $scope->whereNotNull('temperature_min')->orWhereNotNull('temperature_max'));
            }
        }

        $this->applyStopFilters($query, $request);
        $this->applyCargoFlagFilters($query, $request);
        $this->applyEquipmentFilters($query, $request);
        $this->applyAssignmentFilters($query, $request);

        if ($request->boolean('my_bids') && $request->user()) {
            $query->whereHas('offers', fn (Builder $offers) => $offers->where('created_by_user_id', $request->user()->id));
        }

        $user = $request->user();
        $role = $user?->role?->name;

        // The freight marketplace (status=posted) is a shared pool every role browses to find
        // or offer work — only restrict visibility once a load has moved past that public
        // listing stage into an actual booking/relationship.
        if (! $user || $user->isSuperAdminOrMaster() || $status === 'posted') {
            return;
        }

        if ($role === 'user') {
            $query->where('customer_user_id', $user->id);

            return;
        }

        if ($role === 'driver') {
            $query->where(function (Builder $scope) use ($user): void {
                $scope->where('customer_user_id', $user->id)
                    ->orWhere('assigned_driver_user_id', $user->id);
            });

            return;
        }

        if (in_array($role, ['company', 'finance'], true)) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->where(function (Builder $scope) use ($user, $companyIds): void {
                $scope->where('customer_user_id', $user->id)->orWhereIn('company_id', $companyIds);
            });
        }
    }

    protected function applyOrdering(Builder $query, Request $request): void
    {
        if ($request->boolean('tracking')) {
            $query->orderByDesc('updated_at')->orderByDesc('id');

            return;
        }

        [$column, $direction] = match ((string) $request->query('sort', 'price_asc')) {
            'price_desc' => ['budget', 'desc'], 'date_desc' => ['published_at', 'desc'],
            'date_asc' => ['published_at', 'asc'], default => ['budget', 'asc'],
        };
        $query->orderByRaw("{$column} is null")
            ->orderBy($column, $direction)
            ->orderByDesc('id');
    }

    private function applyNumericRange(Builder $query, Request $request, string $parameter, string $column): void
    {
        if ($request->filled("{$parameter}_min")) $query->where($column, '>=', $request->query("{$parameter}_min"));
        if ($request->filled("{$parameter}_max")) $query->where($column, '<=', $request->query("{$parameter}_max"));
    }

    private function applyLocationFilter(Builder $query, string $type, string $value): void
    {
        if ($value === '') return;
        $query->whereHas('stops', fn (Builder $stops) => $stops->where('type', $type)->where(function (Builder $location) use ($value): void {
            $location->where('city', 'like', "%{$value}%")->orWhere('country_code', 'like', "%{$value}%")->orWhere('address', 'like', "%{$value}%");
        }));
    }

    private function applyWhereIn(Builder $query, string $column, mixed $value): void
    {
        $values = $this->csv($value);
        if ($values !== []) $query->whereIn($column, $values);
    }

    /**
     * Route + stop-window filters. Pickup and delivery are separate stop rows, so each constraint
     * has to be expressed against the matching stop type rather than the load itself.
     */
    private function applyStopFilters(Builder $query, Request $request): void
    {
        foreach (['pickup', 'delivery'] as $type) {
            if ($city = trim((string) $request->query("{$type}_city", ''))) {
                $query->whereHas('stops', fn (Builder $stops) => $stops->where('type', $type)->where('city', 'like', "%{$city}%"));
            }
            if ($country = trim((string) $request->query("{$type}_country", ''))) {
                $query->whereHas('stops', fn (Builder $stops) => $stops->where('type', $type)->where(function (Builder $scope) use ($country): void {
                    $scope->where('country_code', strtoupper($country))->orWhere('country_code', 'like', "%{$country}%");
                }));
            }

            $from = $request->query("{$type}_date_from");
            $to = $request->query("{$type}_date_to");
            if (! $request->filled("{$type}_date_from") && ! $request->filled("{$type}_date_to")) continue;

            $query->whereHas('stops', function (Builder $stops) use ($type, $from, $to): void {
                $stops->where('type', $type);
                // A stop's window can straddle the requested range, so compare against both ends.
                // The null branch stays grouped, otherwise the OR would escape the type constraint.
                if ($from) {
                    $stops->where(fn (Builder $scope) => $scope->whereDate('window_ends_at', '>=', $from)->orWhereNull('window_ends_at'));
                }
                if ($to) {
                    $stops->where(fn (Builder $scope) => $scope->whereDate('window_starts_at', '<=', $to)->orWhereNull('window_starts_at'));
                }
            });
        }
    }

    /**
     * Cargo nature chips are OR-ed within the group - picking ADR and Fragile means either.
     */
    private function applyCargoFlagFilters(Builder $query, Request $request): void
    {
        $flags = $this->csv($request->query('cargo_flags'));
        if ($flags === []) return;

        $query->where(function (Builder $group) use ($flags): void {
            foreach ($flags as $flag) {
                $group->orWhere(function (Builder $scope) use ($flag): void {
                    match ($flag) {
                        'adr' => $scope->where('requires_adr', true),
                        'fragile' => $scope->where('is_fragile', true),
                        'temperature_controlled' => $scope->whereNotNull('temperature_min')->orWhereNotNull('temperature_max'),
                        'refrigerated' => $scope->where('vehicle_type', 'like', '%Reefer%')->orWhere('goods_type', 'like', '%Refrigerated%'),
                        'oversized' => $scope->where('oog_in_gauge', false)->orWhereNotNull('oog_length_m')->orWhereNotNull('oog_weight_kg'),
                        'high_value' => $scope->whereNotNull('declared_value')->where('declared_value', '>', 0),
                        'general' => $scope->whereNull('requires_adr')->orWhere('requires_adr', false),
                        default => $scope->where('goods_type', 'like', '%' . str_replace('_', ' ', $flag) . '%'),
                    };
                });
            }
        });
    }

    /**
     * Equipment spans two columns: load type lives in cargo_type (FTL/LTL/FCL/LCL) while the body
     * type lives in vehicle_type (Reefer/Mega/Box/...), so match either.
     */
    private function applyEquipmentFilters(Builder $query, Request $request): void
    {
        $equipment = $this->csv($request->query('equipment_types'));
        if ($equipment === []) return;

        $query->where(function (Builder $group) use ($equipment): void {
            foreach ($equipment as $item) {
                $group->orWhere('cargo_type', $item)
                    ->orWhere('vehicle_type', 'like', "%{$item}%")
                    ->orWhere('container_types', 'like', "%{$item}%");
            }
        });
    }

    private function applyAssignmentFilters(Builder $query, Request $request): void
    {
        $assignment = $this->csv($request->query('assignment'));
        if ($assignment === []) return;
        $userId = $request->user()?->id;

        $query->where(function (Builder $group) use ($assignment, $userId): void {
            foreach ($assignment as $item) {
                $group->orWhere(function (Builder $scope) use ($item, $userId): void {
                    match ($item) {
                        'unassigned' => $scope->whereNull('assigned_driver_user_id'),
                        'assigned_to_me' => $userId ? $scope->where('assigned_driver_user_id', $userId) : $scope->whereRaw('1 = 0'),
                        'assigned_driver' => $scope->whereNotNull('assigned_driver_user_id'),
                        'full_truck' => $scope->whereIn('cargo_type', ['FTL', 'FCL']),
                        'partial_load' => $scope->whereIn('cargo_type', ['LTL', 'LCL', 'Groupage']),
                        'available_capacity' => $scope->whereNull('assigned_driver_user_id')->whereIn('cargo_type', ['LTL', 'LCL', 'Groupage']),
                        default => $scope->whereRaw('1 = 0'),
                    };
                });
            }
        });
    }

    private function csv(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item): bool => $item !== ''));
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return [
            'customer_user_id' => ['sometimes', 'integer', 'exists:users,id'], 'consignee_customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'title' => [$p, 'string', 'max:255'], 'booking_reference' => ['nullable', 'string', 'max:160'], 'insurance' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:120'], 'freight_mode' => ['nullable', 'string', 'max:120'], 'subdepartment' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(Load::STATUSES)], 'transport_type' => ['sometimes', 'in:road,air,sea,rail,warehouse'], 'for_storage' => ['sometimes', 'boolean'],
            'cargo_type' => [$updating ? 'sometimes' : 'required_unless:transport_type,warehouse', 'nullable', 'string', 'max:100'], 'goods_type' => ['nullable', 'string', 'max:100'],
            'hs_codes' => ['nullable', 'array', 'max:20'], 'hs_codes.*.code' => ['required', 'string', 'regex:/^\d{4}(?:\s?\d{2}){1,3}$/'],
            'hs_codes.*.description' => ['nullable', 'string', 'max:1000'], 'hs_codes.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            // Laravel's validate() rebuilds arrays strictly from the rule paths defined here, so the
            // chapter/heading context used to pick a category icon on the frontend must be listed
            // explicitly or it is silently dropped even though the frontend sends it.
            'hs_codes.*.chapterCode' => ['nullable', 'string', 'max:10'], 'hs_codes.*.chapterName' => ['nullable', 'string', 'max:255'],
            'hs_codes.*.headingCode' => ['nullable', 'string', 'max:10'], 'hs_codes.*.headingName' => ['nullable', 'string', 'max:255'],
            'customs_documents' => ['nullable', 'array', 'max:160'],
            'customs_documents.*.code' => ['required', 'string', 'max:20'], 'customs_documents.*.label' => ['required', 'string', 'max:500'],
            'customs_documents.*.source' => ['required', 'in:matched,manual'], 'customs_documents.*.downloadable' => ['required', 'boolean'],
            'weight_kg' => [$updating ? 'sometimes' : 'required_unless:transport_type,warehouse', 'nullable', 'numeric', 'min:0'],
            'length_m' => ['nullable', 'numeric', 'min:0'], 'width_m' => ['nullable', 'numeric', 'min:0'], 'height_m' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'], 'pallets' => ['nullable', 'integer', 'min:0'], 'quantity_measure' => ['nullable', 'string', 'max:255'],
            'teu' => ['nullable', 'string', 'max:80'], 'container_types' => ['nullable', 'string', 'max:255'], 'container_number' => ['nullable', 'string', 'max:255'],
            'etd_at' => ['nullable', 'date'], 'atd_at' => ['nullable', 'date'], 'transit_days' => ['nullable', 'integer', 'min:0', 'max:200'], 'shipper_name' => ['nullable', 'string', 'max:255'],
            'mediator' => ['nullable', 'string', 'max:255'], 'incoterms' => ['nullable', 'string', 'max:80'],
            'price_insurance' => ['nullable', 'string'], 'profit_loss' => ['nullable', 'string'], 'temperature_min' => ['nullable', 'numeric'],
            'temperature_max' => ['nullable', 'numeric'], 'declared_value' => ['nullable', 'numeric', 'min:0'], 'shipment_value_currency' => ['nullable', 'string', 'size:3'], 'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'], 'payment_terms' => ['sometimes', 'string', 'max:50'], 'payment_due_days' => ['nullable', 'integer', 'min:0'],
            'is_negotiable' => ['sometimes', 'boolean'],
            'is_fragile' => ['sometimes', 'boolean'], 'requires_adr' => ['sometimes', 'boolean'], 'requires_tail_lift' => ['sometimes', 'boolean'],
            'must_be_trackable' => ['sometimes', 'boolean'], 'is_urgent' => ['sometimes', 'boolean'], 'loading_methods' => ['nullable', 'array'],
            'toll_roads_included' => ['sometimes', 'boolean'], 'ferry_included' => ['sometimes', 'boolean'], 'cmr_required' => ['sometimes', 'boolean'],
            'pallet_exchange_required' => ['sometimes', 'boolean'], 'customs_required' => ['sometimes', 'boolean'],
            'insurance_required' => ['sometimes', 'boolean'], 'certification_required' => ['sometimes', 'boolean'], 'inspection_services_required' => ['sometimes', 'boolean'],
            'vehicle_type' => ['nullable', 'string', 'max:100'], 'transport_mode' => ['nullable', 'string', 'max:120'], 'special_requirements' => ['nullable', 'array'], 'special_requirements.*' => ['string', 'max:255'], 'characteristics' => ['nullable', 'array'], 'characteristics.*' => ['string', 'max:255'], 'delivery_proof' => ['nullable', 'string', 'max:30'],
            'body_types' => ['nullable', 'array'],
            'storage_type' => ['nullable', 'required_if:transport_type,warehouse', 'string', 'max:100'],
            'warehouse_city' => ['nullable', 'required_if:transport_type,warehouse', 'string', 'max:120'],
            'warehouse_country_code' => ['nullable', 'required_if:transport_type,warehouse', 'string', 'size:2'],
            'warehouse_address' => ['nullable', 'string', 'max:255'],
            'warehouse_latitude' => ['nullable', 'numeric', 'between:-90,90'], 'warehouse_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'warehouse_radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'storage_start_date' => ['nullable', 'required_if:transport_type,warehouse', 'date'], 'storage_end_date' => ['nullable', 'date'],
            'is_storage_ongoing' => ['sometimes', 'boolean'], 'handling_requirements' => ['nullable', 'array'], 'handling_requirements.*' => ['string', 'max:255'],
            'requires_customs_bonded' => ['sometimes', 'boolean'], 'requires_racking' => ['sometimes', 'boolean'], 'requires_security' => ['sometimes', 'boolean'], 'requires_food_grade' => ['sometimes', 'boolean'],
            'rate_unit' => ['nullable', 'string', 'max:50'],
            'container_selections' => ['nullable', 'array'], 'container_selections.*.type' => ['required_with:container_selections', 'string', 'max:20'], 'container_selections.*.quantity' => ['required_with:container_selections', 'integer', 'min:1'],
            'bl_type' => ['nullable', 'string', 'max:30'],
            'dg_un_number' => ['nullable', 'string', 'max:20'], 'dg_imo_class' => ['nullable', 'string', 'max:20'], 'dg_packing_group' => ['nullable', 'string', 'max:10'], 'dg_proper_shipping_name' => ['nullable', 'string', 'max:255'],
            'oog_in_gauge' => ['nullable', 'string', 'max:20'], 'oog_length_m' => ['nullable', 'numeric', 'min:0'], 'oog_width_m' => ['nullable', 'numeric', 'min:0'], 'oog_height_m' => ['nullable', 'numeric', 'min:0'], 'oog_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'contact' => ['nullable', 'array'], 'notes' => ['nullable', 'string'], 'internal_comments' => ['nullable', 'string'],
            'external_comments' => ['nullable', 'string'], 'published_at' => ['nullable', 'date'], 'completed_at' => ['nullable', 'date'],
            'stops' => ['sometimes', 'array', 'min:2'], 'stops.*.type' => ['required_with:stops', 'in:pickup,waypoint,delivery'],
            'stops.*.position' => ['required_with:stops', 'integer', 'min:1'], 'stops.*.place_type' => ['nullable', 'string', 'max:100'], 'stops.*.port' => ['nullable', 'string', 'max:255'], 'stops.*.airport' => ['nullable', 'string', 'max:255'],
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
        if (in_array($request->user()?->role?->name, ['user', 'driver'], true)) {
            $data['customer_user_id'] = $request->user()->id;
        }
        if ($request->user()?->isSuperAdminOrMaster()) {
            $demoCompanyId = Company::query()
                ->where('slug', 'smartfreight-logistics-hub')
                ->value('id');

            if ($demoCompanyId) {
                $data['company_id'] = $demoCompanyId;
            }
        }
        if ($request->user()?->role?->name === 'company' && empty($data['company_id'])) {
            $ownCompanyId = $request->user()->companies()->value('companies.id');

            if ($ownCompanyId) {
                $data['company_id'] = $ownCompanyId;
            }
        }
        $data['customer_user_id'] = $data['customer_user_id'] ?? $request->user()->id;
        $data['status'] = $data['status'] ?? 'pending';
        $data['for_storage'] = $data['for_storage'] ?? (($data['transport_type'] ?? 'road') === 'warehouse');
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
                abort_unless($request->user()?->isSuperAdminOrMaster(), 403, 'Only a superadmin can change a load status.');
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
        $user = $request->user();
        $role = $user?->role?->name;
        $canManageStatus = in_array($role, [
            'driver',
            'company',
            'superadmin',
            'master',
        ], true);

        $data = $request->validate([
            'status' => ['required', Rule::in(Load::STATUSES)],
        ]);

        $isReceivingCustomer = $role === 'user'
            && (int) $load->customer_user_id === (int) $user->id
            && $data['status'] === 'received';
        abort_unless($canManageStatus || $isReceivingCustomer, 403, 'You cannot update this load status.');
        abort_if($data['status'] === 'received' && ! $isReceivingCustomer, 403, 'Only the customer can mark the load as received.');
        abort_if($isReceivingCustomer && $load->status !== 'in_delivery', 409, 'The load can be received only while it is in delivery.');
        abort_if($isReceivingCustomer && ! $load->reviews()->where('reviewer_user_id', $user->id)->exists(), 422, 'Post your review before marking the load as received.');
        abort_if($data['status'] === 'finished', 422, 'Complete the vehicle return inspection before finishing the load.');

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
            } elseif ($user->isSuperAdminOrMaster()) {
                // Superadmin/master can dedicate the load to any company and/or any driver, or neither.
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
                if (in_array($request->user()?->role?->name, ['user', 'driver'], true)) {
                    $data['customer_user_id'] = $request->user()->id;
                }
                $data['customer_user_id'] = $data['customer_user_id'] ?? $request->user()->id;
                $data['status'] = $data['status'] ?? 'pending';
                $data['for_storage'] = $data['for_storage'] ?? (($data['transport_type'] ?? 'road') === 'warehouse');
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
