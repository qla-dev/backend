<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Load;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /** What a transport bid may be priced on. */
    private const TRANSPORT_PRICE_BASIS = ['fixed_total', 'best_bid', 'per_km', 'per_ton', 'per_pallet'];

    /** What a warehousing bid may be priced on - storage is billed per unit and per period, never per km. */
    private const STORAGE_PRICE_BASIS = ['fixed_total', 'best_bid', 'per_pallet_day', 'per_pallet_month', 'per_m2_month', 'per_m3_month', 'custom'];

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';
        $confirmed = $u ? ['sometimes', 'boolean'] : [$p, 'accepted'];

        return [
            'load_id' => [$p, 'integer', 'exists:loads,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'amount' => [$p, 'numeric', 'min:0'], 'currency' => ['sometimes', 'string', 'size:3'], 'status' => ['sometimes', 'string', 'max:50'], 'valid_until' => [$p, 'date'], 'message' => ['nullable', 'string'],
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
        $data = $request->validate($this->rulesForRequest($request, false));
        $this->validateBestBidPaymentTerms($data['price_basis'], $data['payment_terms']);

        $offer = DB::transaction(function () use ($data) {
            $load = Load::query()->lockForUpdate()->findOrFail($data['load_id']);
            $this->validateBidFloor($load, (float) $data['amount'], $data['price_basis'] !== 'best_bid');

            return Offer::query()->create($data);
        });
        $offer->load($this->relations());

        return $this->success((new EntityResource($offer))->resolve($request), 'Offer created successfully.', status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rulesForRequest($request, true, Offer::query()->find($id)));

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

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['driver_user_id' => ['nullable', 'integer', 'exists:users,id']]);

        $offer = DB::transaction(function () use ($id, $data) {
            $offer = Offer::query()->with('freightLoad')->lockForUpdate()->findOrFail($id);
            $driverId = $data['driver_user_id'] ?? $offer->driver_user_id;
            $isDriver = $driverId && User::query()->whereKey($driverId)->whereHas('role', fn ($query) => $query->where('name', 'driver'))->exists();

            if (! $isDriver) {
                throw ValidationException::withMessages(['driver_user_id' => 'Select a user with the driver role.']);
            }

            Offer::query()->where('load_id', $offer->load_id)->whereKeyNot($offer->id)->where('status', 'pending')->update(['status' => 'rejected']);
            $offer->update(['driver_user_id' => $driverId, 'status' => 'accepted']);
            $offer->freightLoad->update([
                'assigned_driver_user_id' => $driverId,
                'company_id' => $offer->company_id ?? $offer->freightLoad->company_id,
                'status' => 'sent',
            ]);

            return $offer->fresh($this->relations());
        });

        return $this->success((new EntityResource($offer))->resolve($request), 'Offer approved and driver assigned.');
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
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
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
