<?php

namespace App\Services;

class LenaCatalog
{
    public const LOCALES = ['en', 'de', 'bs'];

    public function schema(): array
    {
        return json_decode(file_get_contents(resource_path('lena/schema.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function text(string $locale): array
    {
        return trans('lena', [], in_array($locale, self::LOCALES, true) ? $locale : 'en');
    }

    public function payload(): array
    {
        $schema = $this->schema();
        $locales = [];
        foreach (self::LOCALES as $locale) {
            $text = $this->text($locale);
            $text['welcome']['general'] .= "\n\n[[LENA_OPTIONS:".implode(',', $schema['welcome_actions']).']]';
            $steps = [];
            foreach ($schema['steps'] as $key => $step) {
                $steps[$key] = [
                    ...$step,
                    'label' => ($text['ui'][$step['label_key'] ?? ''] ?? '') ?: $text['step_labels'][$key],
                    'question' => app(LenaGuidedAnswerResponder::class)->askStep($key, $step['options'], $locale),
                    'choices' => $this->choices($key, $step, $schema, $text),
                    'unit' => $step['unit'] === 'pieces' ? ($text['labels']['pieces'] ?? 'pcs') : $step['unit'],
                ];
                $steps[$key]['choices_by_transport'] = [];
                foreach ($step['groups_by_transport'] ?? [] as $transport => $group) {
                    $steps[$key]['choices_by_transport'][$transport] = $this->choices($key, [...$step, 'group' => $group], $schema, $text);
                }
            }
            $formFields = [];
            foreach ($schema['form_fields'] ?? [] as $field => $definition) {
                $formFields[$field] = [
                    ...$definition,
                    'label' => $text['ui'][$definition['label_key']] ?? $field,
                    'question' => $steps[$definition['step'] ?? '']['question'] ?? null,
                ];
            }
            $locales[$locale] = [...$text, 'steps' => $steps, 'form_fields' => $formFields];
        }

        $payload = [...$schema, 'locales' => $locales];
        $payload['revision'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $payload;
    }

    private function choices(string $key, array $step, array $schema, array $text): array
    {
        $values = match ($key) {
            'transportType' => ['road', 'air', 'sea', 'rail', 'warehouse'],
            'storageTarget' => ['own', 'exchange'],
            'priceTerms' => ['fixed', 'negotiable'],
            default => $schema['option_groups'][$step['group'] ?? ''] ?? [],
        };
        $choices = array_map(fn ($value) => [
            'value' => $value,
            'label' => $text['labels'][$value] ?? $text['ui'][$value] ?? $value,
        ], $values);
        if ($step['options'] && ! in_array($key, ['transportType', 'storageTarget', 'warehouse', 'priceTerms'], true)) {
            $choices[] = ['value' => '[[LENA_SKIP:'.$key.']]', 'label' => $text['labels']['none'], 'skip' => true];
        }
        $choices[] = ['value' => '[[LENA_SKIP:'.$key.']]', 'label' => $text['labels']['later'], 'skip' => true];

        return $choices;
    }
}
