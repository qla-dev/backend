<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
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
        return ['freightLoad.stops', 'company', 'driver', 'creator'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'], 'driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'amount' => [$p, 'numeric', 'min:0'], 'currency' => ['sometimes', 'string', 'size:3'], 'status' => ['sometimes', 'string', 'max:50'], 'valid_until' => ['nullable', 'date'], 'message' => ['nullable', 'string']];
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
                'status' => 'assigned',
            ]);

            return $offer->fresh($this->relations());
        });

        return $this->success((new EntityResource($offer))->resolve($request), 'Offer approved and driver assigned.');
    }
}
