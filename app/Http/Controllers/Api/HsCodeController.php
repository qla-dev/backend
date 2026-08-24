<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HsCodeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HsCodeController extends Controller
{
    public function index(Request $request, HsCodeSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:300'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ]);

        $results = $search->search($validated['query'], (int) ($validated['limit'] ?? 8));

        return response()->json([
            'message' => 'HS codes retrieved.',
            'data' => $results,
            'meta' => ['total' => count($results), 'version' => 'HS 2022'],
            'errors' => [],
        ]);
    }

    // Batch-resolves a set of already-known codes back to their full catalog entry (description,
    // category names) in one request - used when opening an existing load for editing, since only
    // the bare codes are persisted on the load itself.
    public function bulk(Request $request, HsCodeSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'codes' => ['required', 'array', 'min:1', 'max:100'],
            'codes.*' => ['string'],
        ]);

        $results = $search->resolveByCodes($validated['codes']);

        return response()->json([
            'message' => 'HS codes retrieved.',
            'data' => $results,
            'meta' => ['total' => count($results), 'version' => 'HS 2022'],
            'errors' => [],
        ]);
    }
}
