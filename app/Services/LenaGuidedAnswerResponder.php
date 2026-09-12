<?php

namespace App\Services;

class LenaGuidedAnswerResponder
{
    public function stepLabel(string $step, string $lang): string
    {
        return app(LenaCatalog::class)->text($lang)['step_labels'][$step] ?? $step;
    }

    public function respond(string $answeredStep, string $lang, ?string $answeredValue, ?array $nextStep): string
    {
        $text = app(LenaCatalog::class)->text($lang);
        $prefix = $text['prefixes'][array_rand($text['prefixes'])];
        $label = $this->stepLabel($answeredStep, $lang);
        $value = $answeredValue ?? '';
        if (in_array($answeredStep, ['transportType', 'storageTarget', 'priceTerms'], true)) {
            $value = mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
        }
        $confirmation = strtr($text['templates'][$answeredValue === null ? 'skipped' : 'saved'], [
            ':prefix' => $prefix, ':label' => $label, ':value' => $value,
        ]);
        $next = $nextStep ? $this->askStep($nextStep['key'], $nextStep['hasOptions'], $lang) : $text['templates']['complete'];
        $marker = $nextStep ? "[[LENA_STEP:{$nextStep['key']}]]" : '[[LOAD_READY_TO_POST:complete]]';

        return "{$confirmation}\n\n{$next}\n{$marker}";
    }

    public function askStep(string $step, bool $hasOptions, string $lang): string
    {
        $text = app(LenaCatalog::class)->text($lang);
        $hint = $text['format_hints'][$step] ?? '';

        return strtr($text['templates'][$hasOptions ? 'choose' : ($hint ? 'enter_hint' : 'enter')], [
            ':label' => $this->stepLabel($step, $lang), ':hint' => $hint,
        ]);
    }
}
