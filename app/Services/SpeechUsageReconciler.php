<?php

namespace App\Services;

use App\Models\AiCallLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class SpeechUsageReconciler
{
    public function fetch(string $generationId): ?array
    {
        $response = Http::withToken(config('services.openrouter.api_key'))->connectTimeout(5)->timeout(10)
            ->get('https://openrouter.ai/api/v1/generation', ['id' => $generationId]);
        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data) || ! isset($data['total_cost'])) return null;
        $prompt = $data['native_tokens_prompt'] ?? $data['tokens_prompt'] ?? null;
        $completion = $data['native_tokens_completion'] ?? $data['tokens_completion'] ?? null;
        return [
            'cost_usd' => $data['total_cost'], 'provider' => $data['provider_name'] ?? null,
            'prompt_tokens' => $prompt, 'completion_tokens' => $completion,
            'total_tokens' => $prompt !== null || $completion !== null ? ($prompt ?? 0) + ($completion ?? 0) : null,
            'response_payload' => ['usage_status' => 'complete', 'generation' => $data],
        ];
    }

    public function reconcile(string $generationId): void
    {
        try {
            $attributes = $this->fetch($generationId);
            if (! $attributes) return; // The scheduled retry fills in delayed provider metadata.
            AiCallLog::query()->where('service', 'speech')->where('generation_id', $generationId)
                ->whereNull('cost_usd')->get()->each(fn (AiCallLog $log) => $log->update($attributes));
        } catch (Throwable) {
            // Preserve the pending row and retry later; never fabricate a zero cost.
        }
    }
}
