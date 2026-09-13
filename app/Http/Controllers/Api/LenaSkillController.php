<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LenaSkillCatalog;
use Illuminate\Http\JsonResponse;

class LenaSkillController extends Controller
{
    public function index(LenaSkillCatalog $catalog): JsonResponse
    {
        return response()->json(['message' => 'Lena skills retrieved.', 'data' => $catalog->rows(), 'meta' => [], 'errors' => []]);
    }
}
