<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCallLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    // The current user's own LenaAI token usage, drawn from ai_call_logs. This is deliberately
    // scoped to user_id = the caller - platform-wide totals stay behind AiStatsView (admin-only).
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $since = now()->subDays(29)->startOfDay();

        $daily = AiCallLog::query()->where('user_id', $userId)->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, SUM(total_tokens) as tokens, COUNT(*) as calls')
            ->groupBy('date')->orderBy('date')->get();

        $byService = AiCallLog::query()->where('user_id', $userId)->where('created_at', '>=', $since)
            ->selectRaw('service, SUM(total_tokens) as tokens, COUNT(*) as calls')
            ->groupBy('service')->orderByDesc('tokens')->get();

        $totals = [
            'tokens_30d' => (int) AiCallLog::query()->where('user_id', $userId)->where('created_at', '>=', $since)->sum('total_tokens'),
            'calls_30d' => AiCallLog::query()->where('user_id', $userId)->where('created_at', '>=', $since)->count(),
            'tokens_all_time' => (int) AiCallLog::query()->where('user_id', $userId)->sum('total_tokens'),
            'calls_all_time' => AiCallLog::query()->where('user_id', $userId)->count(),
        ];

        return response()->json([
            'message' => 'Usage retrieved successfully.',
            'data' => ['daily' => $daily, 'by_service' => $byService, 'totals' => $totals],
            'meta' => [],
            'errors' => [],
        ]);
    }
}
