<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Load;
use App\Models\Offer;
use App\Models\User;
use App\Services\ShipmentWorkspaceCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfferController extends CrudController
{
    protected function modelClass(): string
    {
        return Offer::class;
    }

    protected function relations(): array
    {
        return ['freightLoad.stops', 'company', 'driver', 'creator', 'vehicle', 'warehouse', 'parentOffer'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $user = $request->user();
        if (! $user || $user->isSuperAdminOrMaster()) {
            return;
        }

        $companyIds = $user->companies()->pluck('companies.id');
        $query->where(function (Builder $visible) use ($user, $companyIds): void {
            $visible->where('created_by_user_id', $user->id)
                ->orWhereHas('freightLoad', fn (Builder $loads) => $loads->where('customer_user_id', $user->id))
                ->orWhereHas('parentOffer', fn (Builder $parents) => $parents
                    ->where('created_by_user_id', $user->id)
                    ->orWhereIn('company_id', $companyIds));

            if ($companyIds->isNotEmpty()) {
                $visible->orWhereIn('company_id', $companyIds);
            }
        });
    }

    /** What a transport bid may be priced on. */
    private const TRANSPORT_PRICE_BASIS = ['fixed_total', 'best_bid', 'per_km', 'per_ton', 'per_pallet'];

    /** What a warehousing bid may be priced on - storage is billed per unit and per period, never per km. */
    private const STORAGE_PRICE_BASIS = ['fixed_total', 'best_bid', 'per_pallet_day', 'per_pallet_month', 'per_m2_month', 'per_m3_month', 'custom'];

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';
        $confirmed = $u ? ['sometimes', 'boolean'] : [$p, 'accepted'];

        return [
            'load_id' => [$p, 'integer', 'exists:loads,id'], 'request_type' => ['sometimes', Rule::in(['price_offer', 'reservation_request'])], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'amount' => [$p, 'numeric', 'min:0'], 'currency' => ['sometimes', 'string', 'size:3'], 'status' => ['sometimes', Rule::in(['pending', 'accepted', 'not_selected', 'rejected', 'withdrawn', 'expired', 'cancelled'])], 'valid_until' => [$p, 'date'], 'message' => ['nullable', 'string'],
            'price_basis' => [$p, 'string', 'in:'.implode(',', self::TRANSPORT_PRICE_BASIS)],
            'vat' => [$p, 'string', 'in:included,excluded'],
            'payment_terms' => [$p, 'string', 'max:30'],
            'included_charges' => ['nullable', 'array'],
            'included_charges.*' => ['string', 'max:60'],
            'excluded_charges' => ['nullable', 'array'],
            'excluded_charges.*' => ['string', 'max:60'],
            'equipment_type' => [$p, 'string', 'max:60'],
            'vehicle_availability' => [$p, 'string', 'in:available,not_available'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'available_date' => [$p, 'date'],
            'exact_loading_date' => [$p, 'date'],
            'estimated_transit_days' => [$p, 'integer', 'min:0'],
            'estimated_delivery_date' => ['nullable', 'date'],
            'can_perform_as_required' => ['sometimes', 'boolean'],
            'additional_charges' => ['nullable', 'array'],
            'additional_charges.*.type' => ['nullable', 'string', 'max:120'],
            'additional_charges.*.condition' => ['nullable', 'string', 'max:120'],
            'additional_charges.*.rate' => ['nullable', 'numeric'],
            'additional_charges.*.unit' => ['nullable', 'string', 'max:30'],
            'has_exceptions' => ['sometimes', 'boolean'],
            'is_counter' => ['sometimes', 'boolean'],
            'parent_offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'confirmed_authorized' => $confirmed,
            'confirmed_details_match' => $confirmed,
            'confirmed_terms' => $confirmed,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role?->name;
        abort_unless(in_array($role, ['user', 'driver', 'company', 'manager', 'dispatcher', 'customs_officer', 'warehouse', 'superadmin', 'master'], true), 403);

        $identity = ['created_by_user_id' => $user->id, 'request_type' => 'price_offer', 'status' => 'pending'];
        if ($role === 'driver') {
            $identity['driver_user_id'] = $user->id;
            $identity['company_id'] = $user->driver?->primary_company_id;
        } elseif (in_array($role, ['company', 'manager', 'dispatcher', 'customs_officer'], true)) {
            $companyIds = $user->companies()->pluck('companies.id');
            abort_if($companyIds->isEmpty(), 422, 'You are not linked to a company.');
            $companyId = $request->integer('company_id') ?: (int) $companyIds->first();
            abort_unless($companyIds->contains($companyId), 403, 'You can submit offers only for your own company.');
            $identity['company_id'] = $companyId;
            if ($request->filled('driver_user_id')) {
                abort_unless($user->companies()->whereKey($companyId)->whereHas('users', fn (Builder $members) => $members->whereKey($request->integer('driver_user_id')))->exists(), 422, 'The selected driver is not part of this company.');
            }
        }

        if ($role === 'user') {
            $parent = $request->filled('parent_offer_id') ? Offer::query()->with('freightLoad')->find($request->integer('parent_offer_id')) : null;
            abort_unless($request->boolean('is_counter') && $parent && (int) $parent->freightLoad->customer_user_id === (int) $user->id, 403, 'Customers can only counter offers on their own loads.');
        }

        $request->merge($identity);
        $data = $request->validate($this->rulesForRequest($request, false));
        $this->validateBestBidPaymentTerms($data['price_basis'], $data['payment_terms']);

        $offer = DB::transaction(function () use ($data) {
            $load = Load::query()->lockForUpdate()->findOrFail($data['load_id']);
            abort_unless($load->status === 'posted', 409, 'This load no longer accepts offers.');
            $this->validateBidFloor($load, (float) $data['amount'], $data['price_basis'] !== 'best_bid');

            return Offer::query()->create($data);
        });
        $offer->load($this->relations());

        return $this->success((new EntityResource($offer))->resolve($request), 'Offer created successfully.', status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $existing = Offer::query()->with('freightLoad')->findOrFail($id);
        $user = $request->user();
        $isOwner = (int) $existing->freightLoad->customer_user_id === (int) $user->id;
        $isCreator = (int) $existing->created_by_user_id === (int) $user->id;
        abort_unless($user->isSuperAdminOrMaster() || $isOwner || $isCreator, 403, 'You cannot update this offer.');

        if ($request->has('status')) {
            $nextStatus = (string) $request->input('status');
            abort_unless(
                $user->isSuperAdminOrMaster()
                    || ($isOwner && $nextStatus === 'rejected')
                    || ($isCreator && in_array($nextStatus, ['withdrawn', 'cancelled'], true)),
                403,
                'This offer status transition is not allowed.'
            );
        } else {
            abort_unless($isCreator || $user->isSuperAdminOrMaster(), 403, 'Only the offer creator can edit it.');
        }

        $data = $request->validate($this->rulesForRequest($request, true, $existing));

        $offer = DB::transaction(function () use ($id, $data) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($id);
            $this->validateBestBidPaymentTerms(
                $data['price_basis'] ?? $offer->price_basis,
                $data['payment_terms'] ?? $offer->payment_terms,
            );
            $load = Load::query()->lockForUpdate()->findOrFail($data['load_id'] ?? $offer->load_id);

            if (array_key_exists('amount', $data) || array_key_exists('price_basis', $data)) {
                $priceBasis = $data['price_basis'] ?? $offer->price_basis;
                $amount = (float) ($data['amount'] ?? $offer->amount);
                $this->validateBidFloor($load, $amount, $priceBasis !== 'best_bid');
            }

            $offer->update($data);

            return $offer;
        });
        $offer->load($this->relations());

        return $this->success((new EntityResource($offer))->resolve($request), 'Offer updated successfully.');
    }

    public function approve(Request $request, int $id, ShipmentWorkspaceCreator $workspaceCreator): JsonResponse
    {
        $data = $request->validate(['driver_user_id' => ['nullable', 'integer', 'exists:users,id']]);

        [$offer, $workspace] = DB::transaction(function () use ($request, $id, $data, $workspaceCreator) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($id);
            $load = Load::query()->with('shipment')->lockForUpdate()->findOrFail($offer->load_id);
            $user = $request->user();
            abort_unless(
                $user->isSuperAdminOrMaster() || (int) $load->customer_user_id === (int) $user->id,
                403,
                'Only the load owner can approve this request.'
            );
            abort_unless($offer->status === 'pending', 409, 'This request has already been decided.');
            abort_unless($load->status === 'posted', 409, 'This load is no longer open for offers or reservations.');
            abort_unless(! $load->shipmentWorkspace()->exists(), 409, 'A provider has already been selected for this load.');

            $driverId = $data['driver_user_id'] ?? $offer->driver_user_id;
            $isDriver = ! $driverId || User::query()->whereKey($driverId)->whereHas('role', fn ($query) => $query->where('name', 'driver'))->exists();
            if (! $isDriver) {
                throw ValidationException::withMessages(['driver_user_id' => 'Select a user with the driver role.']);
            }

            Offer::query()->where('load_id', $offer->load_id)->whereKeyNot($offer->id)->where('status', 'pending')->update(['status' => 'not_selected']);
            $offer->update(['driver_user_id' => $driverId, 'status' => 'accepted']);
            $workspace = $workspaceCreator->create($load, $offer->fresh(), $user);
            $load->update([
                'assigned_driver_user_id' => $driverId,
                'company_id' => $offer->company_id ?? $load->company_id,
                'status' => 'booked',
                'pre_delivery_status' => null,
                'booking_status' => 'confirmed',
                'booking_reference' => $workspace->reference,
            ]);

            return [$offer->fresh($this->relations()), $workspace];
        });

        return $this->success(array_merge(
            (new EntityResource($offer))->resolve($request),
            ['shipment_workspace' => (new EntityResource($workspace->load(['shipment', 'conversation'])))->resolve($request)]
        ), 'Provider accepted, booking confirmed, and Shipment Workspace created.');
    }

    /**
     * The rules for the load actually being bid on. A storage request is answered with capacity -
     * from when, how much, for how long, at what rate per unit - so the transport commitment fields
     * (truck, loading date, transit time) stop being required and the warehouse ones take over.
     */
    private function rulesForRequest(Request $request, bool $updating, ?Offer $offer = null): array
    {
        $rules = $this->rules($updating);
        $loadId = $request->input('load_id') ?? $offer?->load_id;
        $load = $loadId ? Load::query()->find($loadId) : null;

        if (! $load || ! ($load->for_storage || $load->transport_type === 'warehouse')) {
            return $rules;
        }

        $p = $updating ? 'sometimes' : 'required';
        $isCounter = $request->boolean('is_counter', (bool) $offer?->is_counter);
        $parentOfferId = $request->input('parent_offer_id') ?? $offer?->parent_offer_id;
        $parentWarehouseId = $isCounter && $parentOfferId
            ? Offer::query()->whereKey($parentOfferId)->value('warehouse_id')
            : null;

        return array_merge($rules, [
            'price_basis' => [$p, 'string', 'in:'.implode(',', self::STORAGE_PRICE_BASIS)],
            'equipment_type' => ['nullable', 'string', 'max:60'],
            'vehicle_availability' => ['nullable', 'string', 'in:available,not_available'],
            'available_date' => ['nullable', 'date'],
            'exact_loading_date' => ['nullable', 'date'],
            'estimated_transit_days' => ['nullable', 'integer', 'min:0'],
            'capacity_status' => [$p, 'string', 'in:available,partial,propose_changes'],
            'available_from' => [$p, 'date'],
            'available_capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', 'string', 'max:30'],
            'minimum_storage_period' => ['nullable', 'string', 'max:30'],
            'price_breakdown' => ['nullable', 'array'],
            'price_breakdown.*.service' => ['nullable', 'string', 'max:120'],
            'price_breakdown.*.unit' => ['nullable', 'string', 'max:60'],
            'price_breakdown.*.price' => ['nullable', 'numeric'],
            'services_included' => ['nullable', 'array'],
            'services_included.*' => ['string', 'max:60'],
            'optional_conditions' => ['nullable', 'array'],
            'optional_conditions.*' => ['string', 'max:60'],
            'warehouse_id' => [
                $p,
                'integer',
                Rule::exists('warehouses', 'id')->where(
                    fn ($query) => $isCounter
                        ? $query->where('id', $parentWarehouseId ?? 0)
                        : $query->where('user_id', $request->user()->id)
                ),
            ],
            // A storage bid is not a transport mandate, so the licensing and shipment-details
            // confirmations do not apply to it; confirming the quoted terms still does.
            'confirmed_authorized' => ['sometimes', 'boolean'],
            'confirmed_details_match' => ['sometimes', 'boolean'],
            'confirmed_terms' => $updating ? ['sometimes', 'boolean'] : ['required', 'accepted'],
        ]);
    }

    private function validateBidFloor(Load $load, float $amount, bool $allowBelowFloor = false): void
    {
        if (! $load->is_negotiable || $load->status !== 'posted') {
            throw ValidationException::withMessages(['amount' => 'This load is not accepting offers.']);
        }

        $highestOffer = Offer::query()
            ->where('load_id', $load->id)
            ->where('status', '!=', 'rejected')
            ->max('amount');
        $minimum = $highestOffer !== null ? (float) $highestOffer : (float) ($load->budget ?? 0);

        if (! $allowBelowFloor && $amount < $minimum) {
            throw ValidationException::withMessages([
                'amount' => "The offer must be at least {$load->currency} ".number_format($minimum, 2, '.', ''),
            ]);
        }
    }

    private function validateBestBidPaymentTerms(string $priceBasis, ?string $paymentTerms): void
    {
        if ($priceBasis === 'best_bid' && $paymentTerms !== 'immediate') {
            throw ValidationException::withMessages([
                'payment_terms' => 'Best bid requires immediate payment.',
            ]);
        }
    }
}
