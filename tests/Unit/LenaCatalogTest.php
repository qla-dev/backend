<?php

namespace Tests\Unit;

use App\Services\LenaCatalog;
use App\Services\LenaGuidedAnswerResponder;
use App\Services\LenaLoadQuestionnaire;
use Tests\TestCase;

class LenaCatalogTest extends TestCase
{
    public function test_all_clients_receive_the_same_complete_localized_catalog(): void
    {
        $catalog = app(LenaCatalog::class)->payload();
        $this->assertSame(['en', 'de', 'bs'], array_keys($catalog['locales']));
        foreach ($catalog['locales'] as $lang => $text) {
            $this->assertStringContainsString('[[LENA_OPTIONS:', $text['welcome']['general']);
            // A load chat greets the way the general one does, and names the load when the client
            // knows its reference.
            $this->assertNotEmpty($text['welcome']['load']);
            $this->assertStringContainsString(':load', $text['welcome']['load_named']);
            $this->assertStringNotContainsString(':load', $text['welcome']['load']);
            foreach ($catalog['steps'] as $key => $step) {
                $this->assertNotEmpty($text['steps'][$key]['label']);
                $this->assertSame(app(LenaGuidedAnswerResponder::class)->askStep($key, $step['options'], $lang), $text['steps'][$key]['question']);
                $this->assertSame('[[LENA_SKIP:'.$key.']]', $text['steps'][$key]['choices'][array_key_last($text['steps'][$key]['choices'])]['value']);
            }
            $this->assertContains('rail', array_column($text['steps']['transportType']['choices'], 'value'));
            $this->assertContains('warehouse', array_column($text['steps']['transportType']['choices'], 'value'));
        }
    }

    public function test_every_step_and_form_field_names_an_example_in_every_language(): void
    {
        $catalog = app(LenaCatalog::class)->payload();

        foreach ($catalog['locales'] as $lang => $text) {
            foreach ($catalog['steps'] as $key => $step) {
                $this->assertNotEmpty($text['steps'][$key]['example'], "$lang: $key has no example");
                $this->assertNotEmpty($step['transports'], "$key applies to no transport type");
                // The question a step is asked with shows its example, so the wording and the
                // example are never written twice.
                $shown = $text['format_hints'][$key] ?? $text['steps'][$key]['example'];
                $this->assertStringContainsString($shown, $text['steps'][$key]['question'], "$lang: $key asks without its example");
            }
            foreach ($catalog['form_fields'] as $field => $definition) {
                $this->assertNotEmpty($text['form_fields'][$field]['label'], "$lang: $field has no label");
                $this->assertNotEmpty($text['form_fields'][$field]['question'], "$lang: $field has no question");
                $this->assertNotEmpty($text['form_fields'][$field]['example'], "$lang: $field has no example");
                $this->assertNotSame($field, $text['form_fields'][$field]['label'], "$lang: $field falls back to its own name");
                $this->assertSame($definition['step'], $text['form_fields'][$field]['step']);
            }
            // Option wording the clients used to hold as hardcoded English.
            $this->assertSame(
                array_keys($catalog['locales']['en']['option_descriptions']),
                array_keys($text['option_descriptions']),
                "$lang: option descriptions are not in step with English"
            );
        }
    }

    public function test_form_controls_read_their_choices_from_the_catalog_without_chat_pills(): void
    {
        $fields = app(LenaCatalog::class)->payload()['locales']['en']['form_fields'];

        // A place type is the field's own picker, not what the pickup step asks in the chat.
        $this->assertSame(
            ['Warehouse', 'Terminal', 'AOL / Airport of loading', 'Address'],
            array_column($fields['pickupPlaceType']['choices_by_transport']['road'], 'value')
        );
        $this->assertSame(
            ['Port to Port', 'Port to Door'],
            array_column($fields['deliveryPlaceType']['choices_by_transport']['sea'], 'value')
        );
        $this->assertSame(['m', 'cm', 'mm'], array_column($fields['lengthUnit']['choices'], 'value'));
        // Container categories and option descriptions travel with the choice they belong to.
        $containers = collect($fields['containerSelections']['choices'])->keyBy('value');
        $this->assertSame('Reefer', $containers['40RH']['category']);
        $this->assertSame("40' High Cube Reefer", $containers['40RH']['label']);
        $this->assertSame('Full truck load', collect($fields['cargoType']['choices'])->firstWhere('value', 'FTL')['description']);
        foreach ($fields as $field => $definition) {
            foreach ([$definition['choices'], ...array_values($definition['choices_by_transport'])] as $choices) {
                $this->assertEmpty(array_filter($choices, fn ($choice) => isset($choice['skip'])), "$field offers a chat-only pill");
            }
        }
    }

    public function test_every_option_carries_the_glyph_both_clients_draw_it_with(): void
    {
        $catalog = app(LenaCatalog::class);
        $schema = $catalog->schema();
        $payload = $catalog->payload();

        // A transport type is a truck, a plane, a ship, a train and a warehouse in the form's cards,
        // so the chat's pills are told the same rather than guessing from the label.
        $transportIcons = collect($payload['locales']['en']['steps']['transportType']['choices'])
            ->pluck('icon', 'value')->all();
        $this->assertSame(
            ['road' => 'Truck', 'air' => 'Plane', 'sea' => 'Ship', 'rail' => 'TrainFront', 'warehouse' => 'Warehouse'],
            collect($transportIcons)->only(LenaCatalog::TRANSPORTS)->all()
        );

        foreach ($schema['option_groups'] as $group => $values) {
            // The packaging registry is 300+ UN codes; they share one glyph rather than each naming it.
            if ($group === 'PACKAGE_TYPE_OPTIONS' || ! is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if ($value !== '') {
                    $this->assertArrayHasKey($value, $schema['option_icons'], "$group's \"$value\" has no glyph");
                }
            }
        }
    }

    public function test_questionnaire_order_and_endpoint_schema_have_one_definition(): void
    {
        $step = app(LenaLoadQuestionnaire::class)->nextStep([], collect(), 1);
        $this->assertSame('title', $step['key']);
        $this->assertSame(app(LenaCatalog::class)->schema()['steps']['title']['description'], $step['description']);
    }

    public function test_public_catalog_is_localized_and_does_not_require_a_conversation(): void
    {
        $response = $this->getJson('/api/lena/catalog')->assertOk();
        $this->assertSame(app(LenaCatalog::class)->payload()['revision'], $response->json('data.revision'));
        $this->assertSame('Weight', $response->json('data.locales.en.form_fields.weightKg.label'));
        $this->assertSame('kg', $response->json('data.locales.bs.steps.weight.unit'));
        $road = $response->json('data.locales.en.steps.loadingEquipment.choices_by_transport.road');
        $air = $response->json('data.locales.en.steps.loadingEquipment.choices_by_transport.air');
        $this->assertContains('Vehicle with ramp', array_column($road, 'value'));
        $this->assertContains('Cargo Lift / High Loader Required', array_column($air, 'value'));
        foreach (['en', 'bs', 'de'] as $locale) {
            $text = $response->json('data.locales.'.$locale);
            foreach ($text['ui'] as $key => $value) {
                $this->assertNotSame('', $value, "Empty $locale translation: $key");
            }
        }
    }
}
