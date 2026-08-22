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
}
