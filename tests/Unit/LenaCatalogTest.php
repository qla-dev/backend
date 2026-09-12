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
            $this->assertNotEmpty($text['welcome']['load']);
            foreach ($catalog['steps'] as $key => $step) {
                $this->assertNotEmpty($text['steps'][$key]['label']);
                $this->assertSame(app(LenaGuidedAnswerResponder::class)->askStep($key, $step['options'], $lang), $text['steps'][$key]['question']);
                $this->assertSame('[[LENA_SKIP:'.$key.']]', $text['steps'][$key]['choices'][array_key_last($text['steps'][$key]['choices'])]['value']);
            }
            $this->assertContains('rail', array_column($text['steps']['transportType']['choices'], 'value'));
            $this->assertContains('warehouse', array_column($text['steps']['transportType']['choices'], 'value'));
        }
    }

    public function test_questionnaire_order_and_endpoint_schema_have_one_definition(): void
    {
        $step = app(LenaLoadQuestionnaire::class)->nextStep([], collect(), 1);
        $this->assertSame('title', $step['key']);
        $this->assertSame(app(LenaCatalog::class)->schema()['steps']['title']['description'], $step['description']);
    }
}
