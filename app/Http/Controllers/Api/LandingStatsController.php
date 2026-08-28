<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\HsCode;
use Illuminate\Http\JsonResponse;

class LandingStatsController extends Controller
{
    /**
     * Public, aggregate-only counts used in landing-page module cards.
     * No customer or tariff record is exposed here.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Landing module counts retrieved.',
            'data' => [
                'recipients' => Customer::query()->count(),
                'tariff_codes' => HsCode::query()->count(),
            ],
            'meta' => [],
            'errors' => [],
        ]);
    }
}
