<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Load;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoadController extends CrudController
{
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
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return [
            'customer_user_id' => ['sometimes', 'integer', 'exists:users,id'], 'consignee_customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'title' => [$p, 'string', 'max:255'], 'status' => ['sometimes', Rule::in(Load::STATUSES)], 'transport_type' => ['sometimes', 'in:road,air,sea'],
            'cargo_type' => [$p, 'string', 'max:100'], 'goods_type' => ['nullable', 'string', 'max:100'], 'weight_kg' => [$p, 'numeric', 'min:0.01'],
            'length_m' => ['nullable', 'numeric', 'min:0'], 'width_m' => ['nullable', 'numeric', 'min:0'], 'height_m' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'], 'pallets' => ['nullable', 'integer', 'min:0'], 'temperature_min' => ['nullable', 'numeric'],
            'temperature_max' => ['nullable', 'numeric'], 'declared_value' => ['nullable', 'numeric', 'min:0'], 'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'], 'payment_terms' => ['sometimes', 'string', 'max:50'], 'payment_due_days' => ['nullable', 'integer', 'min:0'],
            'is_fragile' => ['sometimes', 'boolean'], 'requires_adr' => ['sometimes', 'boolean'], 'requires_tail_lift' => ['sometimes', 'boolean'],
            'must_be_trackable' => ['sometimes', 'boolean'], 'is_urgent' => ['sometimes', 'boolean'], 'loading_methods' => ['nullable', 'array'],
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
        $data['customer_user_id'] = $data['customer_user_id'] ?? $request->user()->id;
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
        $load = DB::transaction(function () use ($id, $data, $hasStops, $stops) {
            $load = Load::query()->findOrFail($id);
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
}
