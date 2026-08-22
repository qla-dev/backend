<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenRouterLoadScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoadScanController extends Controller
{
    public function store(Request $request, OpenRouterLoadScanner $scanner): JsonResponse
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*.base64' => ['required', 'string'],
            'images.*.mimeType' => ['sometimes', 'nullable', 'string', 'in:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'],
            'images.*.filename' => ['sometimes', 'nullable', 'string', 'max:255'],
            'current' => ['sometimes', 'nullable', 'array'],
        ], [
            'images.required' => 'Add at least one photo.',
            'images.min' => 'Add at least one photo.',
            'images.max' => 'You can add at most 5 photos.',
            'images.*.base64.required' => 'The selected photo could not be processed.',
            'images.*.mimeType.in' => 'This file format is not supported.',
        ]);

        if (! config('services.openrouter.api_key')) {
            return response()->json([
                'message' => 'AI document scanning is not configured on the server.',
                'data' => null,
                'meta' => [],
                'errors' => [],
            ], 503);
        }

        $result = $scanner->scan($validated['images'], $validated['current'] ?? []);

        return response()->json(['message' => 'Document scanned.', 'data' => $result, 'meta' => [], 'errors' => []]);
    }

    public function scanText(Request $request, OpenRouterLoadScanner $scanner): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:1', 'max:4000'],
            'current' => ['sometimes', 'nullable', 'array'],
        ], [
            'description.required' => 'Describe the load first.',
            'description.min' => 'Add a bit more detail about the load.',
        ]);

        if (! config('services.openrouter.api_key')) {
            return response()->json([
                'message' => 'AI document scanning is not configured on the server.',
                'data' => null,
                'meta' => [],
                'errors' => [],
            ], 503);
        }

        $result = $scanner->scanText($validated['description'], $validated['current'] ?? []);

        return response()->json(['message' => 'Description parsed.', 'data' => $result, 'meta' => [], 'errors' => []]);
    }
}
