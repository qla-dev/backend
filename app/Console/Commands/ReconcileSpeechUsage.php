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
        $reconciler->reconcilePending(AiCallLog::query(), 100);
        return self::SUCCESS;
    }
}
