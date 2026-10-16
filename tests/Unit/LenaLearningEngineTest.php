<?php
namespace Tests\Unit;
use App\Services\LenaLearningEngine;
use Tests\TestCase;
class LenaLearningEngineTest extends TestCase
{
    public function test_new_sessions_use_ai_instead_of_fixed_questions(): void
    {
        $this->assertSame(2, LenaLearningEngine::VERSION);
        $this->assertSame('ai', (new LenaLearningEngine)->initial()['mode']);
        foreach (['en', 'bs', 'hr', 'sr', 'de'] as $locale) {
            $text = trans('lena-learning', [], $locale);
            $this->assertArrayNotHasKey('lessons', $text);
        }
    }
    public function test_latest_saved_mode_wins_even_when_timestamps_tie(): void
    {
        $messages = collect([
            (object) ['id' => 1, 'body' => '[[LENA_ACTION:learn]]', 'sent_at' => '2026-10-06 12:00:00'],
            (object) ['id' => 2, 'body' => '[[LENA_ACTION:free]]', 'sent_at' => '2026-10-06 12:00:00'],
        ]);
        $method = new \ReflectionMethod(\App\Http\Controllers\Api\DispatchChatController::class, 'activeGuidedMode');
        $this->assertSame('free', $method->invoke(new \App\Http\Controllers\Api\DispatchChatController, $messages));
    }
}
