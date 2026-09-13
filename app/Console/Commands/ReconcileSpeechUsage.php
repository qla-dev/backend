<?php

namespace App\Console\Commands;

use App\Models\AiCallLog;
use App\Services\SpeechUsageReconciler;
use Illuminate\Console\Command;

class ReconcileSpeechUsage extends Command
{
    protected $signature = 'lena:reconcile-speech-usage';
    protected $description = 'Fill pending speech generation costs from OpenRouter billing metadata';

    public function handle(SpeechUsageReconciler $reconciler): int
    {
        AiCallLog::query()->where('service', 'speech')->whereNull('cost_usd')->whereNotNull('generation_id')
            ->where('created_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(100)->pluck('generation_id')
            ->each(fn (string $id) => $reconciler->reconcile($id));
        return self::SUCCESS;
    }
}
