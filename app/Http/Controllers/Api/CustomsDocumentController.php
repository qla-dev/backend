<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CustomsDocumentCatalog;
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

    public function download(string $code, CustomsDocumentCatalog $catalog): BinaryFileResponse
    {
        $document = $catalog->find($code);
        abort_unless($document, 404);

        $filename = $catalog->templateFilename($document);
        abort_unless($filename, 404);

        $path = resource_path("customs-document-templates/{$filename}");
        abort_unless(is_file($path), 404);

        return response()->download($path, "{$code}-template.docx");
    }
}
