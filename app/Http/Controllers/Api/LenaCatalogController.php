<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LenaCatalog;
use Illuminate\Http\JsonResponse;

class LenaCatalogController extends Controller
{
    public function show(LenaCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->payload()]);
    }
}
