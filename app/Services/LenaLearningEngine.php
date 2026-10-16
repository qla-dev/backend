<?php

/* Archived fixed pilot, retained for reference; no longer executed.
namespace App\Services;

/ ** Versioned, deterministic pilot. Answers and completion never come from an LLM. * /
class LenaLearningEngine
{
    public const PROGRAM = 'user-onboarding';
    public const VERSION = 1;
    private const ANSWERS = ['route' => 'b', 'documents' => 'a', 'tracking' => 'c'];

    public function initial(): array
    {
        return ['phase' => 'assessment', 'index' => 0, 'attempts' => [], 'mastered' => [], 'assisted' => false];
    }

    public function lesson(array $state): string
    {
        return array_keys(self::ANSWERS)[$state['index']] ?? 'tracking';
    }

    public function advance(array $state, string $input): array
    {
        if ($state['phase'] === 'completed' || $input === '[[LENA_ACTION:learn]]') {
            return [$state, 'resume'];
        }
        $lesson = $this->lesson($state);
        $phase = $state['phase'];
        if ($input === "[[LENA_LEARN:{$phase}:{$lesson}:hint]]") {
            $state['assisted'] = true;
            return [$state, 'hint'];
        }
        if (! preg_match('/^\[\[LENA_LEARN:(assessment|lesson|final):(route|documents|tracking):([abc])\]\]$/', trim($input), $match)
            || $match[1] !== $phase || $match[2] !== $lesson) {
            return [$state, 'choose'];
        }
        $correct = $match[3] === self::ANSWERS[$lesson];
        $state['attempts'][] = ['phase' => $phase, 'lesson' => $lesson, 'answer' => $match[3], 'correct' => $correct, 'assisted' => $state['assisted']];
        // Only a correct, independent final answer proves this pilot's competency.
        if ($phase === 'final' && $correct && ! $state['assisted']) {
            $state['mastered'] = array_values(array_unique([...$state['mastered'], $lesson]));
        }
        if (! $correct && $phase !== 'assessment') {
            $state['assisted'] = true;
            return [$state, 'incorrect'];
        }
        $state['index']++;
        $state['assisted'] = false;
        if ($state['index'] === count(self::ANSWERS)) {
            $state['index'] = 0;
            $state['phase'] = match ($phase) {
                'assessment' => 'lesson',
                'lesson' => 'final',
                default => count($state['mastered']) === count(self::ANSWERS) ? 'completed' : 'final',
            };
            if ($phase === 'final' && $state['phase'] === 'final') {
                return [$state, 'repeat'];
            }
        }
        return [$state, $correct ? 'correct' : 'assessment_incorrect'];
    }

    public function render(array $state, string $feedback, array $text): string
    {
        $prefix = $text['feedback'][$feedback] ?? '';
        if ($state['phase'] === 'completed') {
            return $text['completed'];
        }
        $lesson = $this->lesson($state);
        $content = $text['lessons'][$lesson];
        $body = $text['title']."\n".$text['phases'][$state['phase']].' '.($state['index'] + 1).'/3'
            ."\n".str_replace(':count', (string) count($state['mastered']), $text['progress']);
        if ($prefix !== '') $body .= "\n\n".$prefix;
        if ($feedback === 'hint') $body .= "\n\n".$content['hint'];
        if ($state['phase'] === 'lesson') $body .= "\n\n".$content['teaching'];
        $body .= "\n\n".$content['question'];
        foreach ($content['choices'] as $key => $label) $body .= "\n".strtoupper($key).'. '.$label;
        return $body."\n[[LENA_LEARN_CARD:{$state['phase']}:{$lesson}]]";
    }
}

*/

namespace App\Services;

class LenaLearningEngine
{
    public const PROGRAM = 'user-onboarding';
    public const VERSION = 2;

    public function initial(): array
    {
        return ['mode' => 'ai', 'phase' => 'onboarding', 'turns' => 0, 'source_ids' => []];
    }
}
