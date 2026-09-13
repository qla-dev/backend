<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContainerRecommendationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerRecommendationController extends Controller
{
    public function __invoke(Request $request, ContainerRecommendationEngine $engine): JsonResponse
    {
        $rules = ['transportType' => ['nullable', 'string', 'in:road,air,sea,rail,warehouse'],
            'dimensionScope' => ['nullable', 'string', 'in:overall,per_unit'],
            'requiresAdr' => ['nullable', 'boolean'], 'specialRequirements' => ['nullable', 'array', 'max:50'],
            'specialRequirements.*' => ['string', 'max:255'], 'hsCodes' => ['nullable', 'array', 'max:100']];
        foreach (['weightKg', 'volumeM3'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:100000000'];
        }
        foreach (['lengthM', 'widthM', 'heightM'] as $field) $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:1000'];
        $rules['pallets'] = ['nullable', 'integer', 'min:0', 'max:1000000'];
        foreach (['temperatureMin', 'temperatureMax'] as $field) $rules[$field] = ['nullable', 'numeric'];
        foreach (['cargoType', 'goodsType', 'characteristics', 'quantityMeasure'] as $field) $rules[$field] = ['nullable', 'string', 'max:1000'];

        return response()->json(['data' => $engine->recommend($request->validate($rules)), 'meta' => [], 'errors' => []]);
    }
}
