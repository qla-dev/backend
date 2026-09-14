<?php

namespace App\Services;

use App\Support\SerbianCyrillic;

class LenaCatalog
{
    public const LOCALES = ['en', 'de', 'bs', 'hr', 'sr'];

    public const TRANSPORTS = ['road', 'air', 'sea', 'rail', 'warehouse'];

    public function schema(): array
    {
        return (new ContainerTypeCatalog)->applyToSchema(
            json_decode(file_get_contents(resource_path('lena/schema.json')), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function text(string $locale): array
    {
        return trans('lena', [], in_array($locale, self::LOCALES, true) ? $locale : 'en');
    }

    /** The steps that apply to one transport type, in the order the questionnaire asks them. */
    public function steps(string $transport): array
    {
        return array_filter(
            $this->schema()['steps'],
            fn (array $step) => in_array($transport, $step['transports'], true),
        );
    }

    /** Every draft field a step fills in, including the ones only one transport type has. */
    public function stepFields(array $step, string $transport): array
    {
        return array_values(array_unique(array_merge(
            $step['form_fields'],
            $step['form_fields_by_transport'][$transport] ?? [],
        )));
    }

    public function answerLabel(string $step, string $value, string $locale, string $transport = 'road'): string
    {
        $schema = $this->schema();
        $definition = $schema['steps'][$step] ?? null;
        if (! $definition) return $value;
        $definition['group'] = $definition['groups_by_transport'][$transport] ?? $definition['group'];
        $choices = collect($this->choices($step, $definition, $schema, $this->text($locale)))->keyBy('value');
        $values = $definition['multiple'] ? array_map('trim', explode(',', $value)) : [$value];

        return implode(', ', array_map(fn ($entry) => $choices->get($entry)['label'] ?? $entry, $values));
    }

    public function payload(): array
    {
        $schema = $this->schema();
        $locales = [];
        $legalSkills = array_values(array_filter(app(LenaSkillCatalog::class)->rows(),
            fn (array $row) => $row['folder'] === 'legal' && $row['kind'] === 'skill'));
        foreach (self::LOCALES as $locale) {
            $text = $this->text($locale);
            $text['legal_choices'] = array_map(fn (array $skill) => [
                'label' => $skill['names'][$locale] ?? $skill['names']['bs'] ?? $skill['name'],
                'value' => $skill['names'][$locale] ?? $skill['names']['bs'] ?? $skill['name'],
                'icon' => match ($skill['id']) {
                    'legal/skills/calculate-packing-list-cbm.md' => 'Ruler',
                    'legal/skills/compare-lcl-fcl.md' => 'Container',
                    'legal/skills/reconcile-declaration-ocp-payments.md' => 'Landmark',
                    default => 'FileSearch',
                },
            ], $legalSkills);
            $text['legal_choices'][] = ['label' => $text['legal_free_chat'], 'value' => $text['legal_free_chat'], 'icon' => 'MessageCircle'];
            $text['welcome']['general'] .= "\n\n[[LENA_OPTIONS:".implode(',', $schema['welcome_actions']).']]';
            $steps = [];
            foreach ($schema['steps'] as $key => $step) {
                $steps[$key] = [
                    ...$step,
                    'label' => ($text['ui'][$step['label_key'] ?? ''] ?? '') ?: $text['step_labels'][$key],
                    'question' => app(LenaGuidedAnswerResponder::class)->askStep($key, $step['options'], $locale),
                    'example' => $text['examples'][$key],
                    'choices' => $this->choices($key, $step, $schema, $text),
                    'unit' => $step['unit'] === 'pieces' ? ($text['labels']['pieces'] ?? 'pcs') : $step['unit'],
                ];
                $steps[$key]['choices_by_transport'] = [];
                $steps[$key]['fields_by_transport'] = [];
                foreach ($step['groups_by_transport'] ?? [] as $transport => $group) {
                    $steps[$key]['choices_by_transport'][$transport] = $this->choices($key, [...$step, 'group' => $group], $schema, $text, transport: $transport);
                }
                foreach (self::TRANSPORTS as $transport) {
                    $steps[$key]['fields_by_transport'][$transport] = $this->stepFields($step, $transport);
                }
            }
            $formFields = [];
            foreach ($schema['form_fields'] ?? [] as $field => $definition) {
                $step = $steps[$definition['step']] ?? null;
                // A field picks from its own list where it has one (a place type, a unit), and
                // otherwise from whatever the step that owns it asks about.
                $ownGroups = isset($definition['group']) || isset($definition['groups_by_transport']);
                $formFields[$field] = [
                    ...$definition,
                    'label' => $text['ui'][$definition['label_key']] ?? $field,
                    'question' => $step['question'] ?? null,
                    // What the form shows as the field's placeholder: the field's own example where
                    // it has one, otherwise the whole answer its step is asked with.
                    'example' => $definition['example'] ?? $step['example'] ?? '',
                    'mask' => $step['mask'] ?? null,
                    'unit' => $step['unit'] ?? '',
                    'multiple' => $step['multiple'] ?? false,
                    // A form control offers only real values - "choose later" is a chat answer.
                    'choices' => $this->choices(
                        $ownGroups ? $field : $definition['step'],
                        ['options' => false, 'group' => $ownGroups ? ($definition['group'] ?? null) : ($schema['steps'][$definition['step']]['group'] ?? null)],
                        $schema,
                        $text,
                        withSkip: false,
                    ),
                    'choices_by_transport' => [],
                ];
                foreach ($definition['groups_by_transport'] ?? $schema['steps'][$definition['step']]['groups_by_transport'] ?? [] as $transport => $group) {
                    $formFields[$field]['choices_by_transport'][$transport] = $this->choices($field, ['options' => false, 'group' => $group], $schema, $text, withSkip: false, transport: $transport);
                }
            }
            $locales[$locale] = [...$text, 'steps' => $steps, 'form_fields' => $formFields];
        }

        $locales['sr_cyrl'] = SerbianCyrillic::convert($locales['sr']);

        // Titles for the source links under Lena's legal answers. Served here so clients deployed
        // without the backend checkout still resolve every catalogue entry.
        $legalSources = collect(app(LegalSourceCatalog::class)->sources())->mapWithKeys(fn (array $source) => [$source['id'] => $source['file']])->all();

        $payload = [...$schema, 'legal_sources' => $legalSources, 'locales' => $locales];
        $payload['revision'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $payload;
    }

    private function choices(string $key, array $step, array $schema, array $text, bool $withSkip = true, ?string $transport = null): array
    {
        $values = match ($key) {
            'transportType' => self::TRANSPORTS,
            'storageTarget' => ['own', 'exchange'],
            'priceTerms' => ['fixed', 'negotiable'],
            default => $schema['option_groups'][$step['group'] ?? ''] ?? [],
        };
        $override = $schema['label_overrides'][$transport ?? ''] ?? [];
        $choices = array_map(function ($value) use ($text, $schema, $override) {
            $choice = [
                'value' => $value,
                'label' => isset($override[$value])
                    ? ($text['ui'][$override[$value]] ?? $value)
                    : ($text['labels'][$value] ?? $text['ui'][$value] ?? $value),
            ];
            if (isset($text['option_descriptions'][$value])) {
                $choice['description'] = $text['option_descriptions'][$value];
            }
            if (isset($schema['container_categories'][$value])) {
                $choice['category'] = $schema['container_categories'][$value];
            }
            // The glyph both clients draw this option with, so a card and a chat pill agree.
            if (isset($schema['option_icons'][$value])) {
                $choice['icon'] = $schema['option_icons'][$value];
            }

            return $choice;
        }, is_array($values) ? $values : []);
        if (! $withSkip) {
            return $choices;
        }
        if ($step['options'] && ! in_array($key, ['transportType', 'storageTarget', 'warehouse', 'priceTerms'], true)) {
            $choices[] = ['value' => '[[LENA_SKIP:'.$key.']]', 'label' => $text['labels']['none'], 'skip' => true];
        }
        $choices[] = ['value' => '[[LENA_SKIP:'.$key.']]', 'label' => $text['labels']['later'], 'skip' => true];

        return $choices;
    }
}
