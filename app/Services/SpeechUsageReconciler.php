<?php

namespace App\Services;

use App\Models\AiCallLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class SpeechUsageReconciler
{
    public function reconcilePending(Builder $query, int $limit = 5): void
    {
        $rows = (clone $query)->where('service', 'speech')->whereNull('cost_usd')
            ->whereNotNull('generation_id')->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $started = microtime(true);
        foreach ($rows as $row) {
            if (microtime(true) - $started >= 10) break;
            $this->reconcile($row->generation_id);
            // Rotate unresolved entries so one unavailable generation cannot block the backlog.
            $row->touch();
        }
    }

    public function fetch(string $generationId): ?array
    {
        $response = Http::withToken(config('services.openrouter.api_key'))->connectTimeout(5)->timeout(10)
            ->get('https://openrouter.ai/api/v1/generation', ['id' => $generationId]);
        $data = $response->json('data');
        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('Speech billing provider lookup failed', [
                'generation_id' => $generationId, 'http_status' => $response->status(),
            ]);
        }
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
        } catch (Throwable $exception) {
            Log::warning('Speech billing reconciliation failed', [
                'generation_id' => $generationId, 'error' => $exception->getMessage(),
            ]);
        }
    }
}
