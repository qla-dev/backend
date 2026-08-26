<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\WarehouseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WarehouseRequestController extends CrudController
{
    protected function modelClass(): string
    {
        return WarehouseRequest::class;
    }

    protected function relations(): array
    {
        return ['customer'];
    }

    protected function searchColumns(): array
    {
        return ['title', 'city', 'storage_type'];
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return [
            'customer_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'title' => [$p, 'string', 'max:255'],
            'status' => ['sometimes', 'in:posted,cancelled'],
            'storage_type' => [$p, 'string', 'max:100'],
            'pallets' => ['nullable', 'integer', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'city' => [$p, 'string', 'max:120'],
            'country_code' => [$p, 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'start_date' => [$p, 'date'],
            'end_date' => ['nullable', 'date'],
            'is_ongoing' => ['sometimes', 'boolean'],
            'handling_requirements' => ['nullable', 'array'],
            'handling_requirements.*' => ['string', 'max:255'],
            'temperature_min' => ['nullable', 'numeric'],
            'temperature_max' => ['nullable', 'numeric'],
            'requires_customs_bonded' => ['sometimes', 'boolean'],
            'requires_racking' => ['sometimes', 'boolean'],
            'requires_insurance' => ['sometimes', 'boolean'],
            'requires_security' => ['sometimes', 'boolean'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'rate_unit' => ['nullable', 'string', 'max:50'],
            'is_negotiable' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'internal_comments' => ['nullable', 'string'],
            'external_comments' => ['nullable', 'string'],
            'contact' => ['nullable', 'array'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['customer_user_id'] = $data['customer_user_id'] ?? $request->user()->id;
        $data['status'] = $data['status'] ?? 'posted';
        $data['public_id'] = (string) Str::uuid();
        $data['published_at'] = $data['published_at'] ?? now();

        $warehouseRequest = WarehouseRequest::query()->create($data);
        $warehouseRequest->load($this->relations());

        return $this->success((new EntityResource($warehouseRequest))->resolve($request), 'Warehouse request created successfully.', status: 201);
    }
}
