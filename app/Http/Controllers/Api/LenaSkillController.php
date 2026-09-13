<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LenaSkillCatalog;
use App\Services\LenaSkillResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LenaSkillController extends Controller
{
    public function index(LenaSkillCatalog $catalog): JsonResponse
    {
        return response()->json(['message' => 'Lena skills retrieved.', 'data' => $catalog->rows(), 'meta' => [], 'errors' => []]);
    }

    /** A JSON data file that a skill lists among its resources. Only files some resources.json names can be opened. */
    public function file(Request $request, LenaSkillResources $resources): JsonResponse
    {
        $path = (string) $request->query('path', '');
        abort_unless(in_array($path, $resources->referencedFiles(), true), 404);

        return response()->json(['message' => 'Resource file retrieved.', 'data' => ['path' => $path, 'content' => (string) file_get_contents(base_path($path))], 'meta' => [], 'errors' => []]);
    }
}
