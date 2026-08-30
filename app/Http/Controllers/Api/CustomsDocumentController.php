<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CustomsDocumentCatalog;
use App\Services\CustomsDocumentGenerator;
use App\Models\Load;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomsDocumentController extends Controller
{
    public function index(Request $request, CustomsDocumentCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $documents = $catalog->catalog($validated['search'] ?? null);

        return response()->json([
            'message' => 'Customs document catalog retrieved.',
            'data' => $documents,
            'meta' => ['total' => count($documents)],
            'errors' => [],
        ]);
    }

    public function match(Request $request, CustomsDocumentCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'codes' => ['present', 'array', 'max:20'],
            'codes.*' => ['string', 'max:30'],
        ]);
        $documents = $catalog->matching($validated['codes']);

        return response()->json([
            'message' => 'Required customs documents matched.',
            'data' => $documents,
            'meta' => ['total' => count($documents)],
            'errors' => [],
        ]);
    }

    public function download(Request $request, Load $load, string $code, CustomsDocumentGenerator $generator): BinaryFileResponse
    {
        $validated = $request->validate([
            'form_data' => ['sometimes', 'array'],
            'form_data.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value) || mb_strlen((string) $value) > 5000) {
                    $fail("The {$attribute} field is invalid.");
                }
            }],
        ]);
        $file = $generator->generate($load, $code, $validated['form_data'] ?? []);

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend(true);
    }
}
