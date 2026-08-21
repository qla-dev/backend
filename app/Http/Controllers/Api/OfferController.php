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
        return ['freightLoad.stops', 'company', 'driver', 'creator', 'vehicle'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';
        $confirmed = $u ? ['sometimes', 'boolean'] : [$p, 'accepted'];

        return [
            'load_id' => [$p, 'integer', 'exists:loads,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'amount' => [$p, 'numeric', 'min:0'], 'currency' => ['sometimes', 'string', 'size:3'], 'status' => ['sometimes', 'string', 'max:50'], 'valid_until' => [$p, 'date'], 'message' => ['nullable', 'string'],
            'price_basis' => [$p, 'string', 'in:fixed_total,per_km,per_ton,per_pallet'],
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
            'confirmed_authorized' => $confirmed,
            'confirmed_details_match' => $confirmed,
            'confirmed_terms' => $confirmed,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $offer = DB::transaction(function () use ($data) {
            $load = Load::query()->lockForUpdate()->findOrFail($data['load_id']);
            $this->validateBidFloor($load, (float) $data['amount']);

            return Offer::query()->create($data);
        });
        $offer->load($this->relations());

        return $this->success((new EntityResource($offer))->resolve($request), 'Offer created successfully.', status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        $offer = DB::transaction(function () use ($id, $data) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($id);
            $load = Load::query()->lockForUpdate()->findOrFail($data['load_id'] ?? $offer->load_id);

            if (array_key_exists('amount', $data)) {
                $this->validateBidFloor($load, (float) $data['amount']);
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

    private function validateBidFloor(Load $load, float $amount): void
    {
        if (! $load->is_negotiable || $load->status !== 'posted') {
            throw ValidationException::withMessages(['amount' => 'This load is not accepting offers.']);
        }

        $highestOffer = Offer::query()
            ->where('load_id', $load->id)
            ->where('status', '!=', 'rejected')
            ->max('amount');
        $minimum = $highestOffer !== null ? (float) $highestOffer : (float) ($load->budget ?? 0);

        if ($amount < $minimum) {
            throw ValidationException::withMessages([
                'amount' => "The offer must be at least {$load->currency} ".number_format($minimum, 2, '.', ''),
            ]);
        }
    }
}
