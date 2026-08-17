<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenRouterBulkLoadScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkLoadScanController extends Controller
{
    public function store(Request $request, OpenRouterBulkLoadScanner $scanner): JsonResponse
    {
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*.base64' => ['required', 'string'],
            'images.*.mimeType' => ['sometimes', 'nullable', 'string', 'in:image/jpeg,image/png,image/webp,image/heic,image/heif'],
        ], [
            'images.required' => 'Add at least one photo.',
            'images.min' => 'Add at least one photo.',
            'images.max' => 'You can add at most 5 photos.',
            'images.*.base64.required' => 'The selected photo could not be processed.',
            'images.*.mimeType.in' => 'This photo format is not supported.',
        ]);

        if (! config('services.openrouter.api_key')) {
            return response()->json([
                'message' => 'AI document scanning is not configured on the server.',
                'data' => null,
                'meta' => [],
                'errors' => [],
            ], 503);
        }

        $result = $scanner->scan($validated['images']);

        return response()->json(['message' => 'Document scanned.', 'data' => $result, 'meta' => [], 'errors' => []]);
    }

    public function scanText(Request $request, OpenRouterBulkLoadScanner $scanner): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'min:8', 'max:40000'],
        ], [
            'text.required' => 'Add the spreadsheet data first.',
            'text.min' => 'There is not enough data to read.',
            'text.max' => 'That file is too large. Please split it into smaller batches.',
        ]);

        if (! config('services.openrouter.api_key')) {
            return response()->json([
                'message' => 'AI document scanning is not configured on the server.',
                'data' => null,
                'meta' => [],
                'errors' => [],
            ], 503);
        }

        $result = $scanner->scanText($validated['text']);

        return response()->json(['message' => 'Spreadsheet parsed.', 'data' => $result, 'meta' => [], 'errors' => []]);
    }
}
