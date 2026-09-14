<?php

namespace Tests\Unit;

use App\Services\ContainerRecommendationEngine;
use PHPUnit\Framework\TestCase;

/** Pure unit tests: no Laravel application or database is booted. */
class ContainerRecommendationEngineTest extends TestCase
{
    private function recommend(array $overrides = []): array
    {
        return (new ContainerRecommendationEngine)->recommend(array_replace([
            'transportType' => 'sea', 'weightKg' => 24000, 'volumeM3' => 33.2,
            'pallets' => 24, 'quantityMeasure' => 'pieces', 'cargoType' => 'General Cargo',
        ], $overrides));
    }

    public function test_example_returns_three_separate_options_with_notes_without_mutating_cargo(): void
    {
        $result = $this->recommend();
        $this->assertSame('estimated', $result['status']);
        $this->assertSame(['40HC', '40STD', '20GP'], array_column($result['candidates'], 'type'));
        $this->assertSame([1, 1, 2], array_column($result['candidates'], 'quantity'));
        $this->assertNotEmpty($result['candidates'][0]['notes']);
        $this->assertArrayNotHasKey('containerSelections', $result);
        $this->assertSame($result['candidates'][0]['score'], $result['candidates'][1]['score']);
        $this->assertGreaterThan($result['candidates'][2]['score'], $result['candidates'][0]['score']);
    }

    public function test_volume_and_payload_use_ceiling_and_the_larger_count(): void
    {
        $this->assertSame(5, $this->recommend(['weightKg' => 31000, 'volumeM3' => 300])['candidates'][0]['quantity']);
        $this->assertSame(3, $this->recommend(['weightKg' => 50001, 'volumeM3' => 10])['candidates'][0]['quantity']);
        $this->assertSame(1, $this->recommend(['weightKg' => 25000, 'volumeM3' => 69])['candidates'][0]['quantity']);
        $this->assertSame(2, $this->recommend(['weightKg' => 25000, 'volumeM3' => 69.001])['candidates'][0]['quantity']);
    }

    /** Conversation 119: LenaAI answered 8 x 40' from generic figures. The skill's worked example must match the engine. */
    public function test_skill_worked_example_matches_the_engine(): void
    {
        $result = $this->recommend(['weightKg' => 65000, 'volumeM3' => 200, 'pallets' => null]);
        $options = array_map(fn ($c) => $c['quantity'].' × '.$c['type'], $result['candidates']);
        $this->assertSame(['3 × 40HC', '4 × 40STD', '8 × 20GP'], $options);
        $this->assertGreaterThan(90, $result['candidates'][0]['volumeUtilization']);
        $skill = file_get_contents(__DIR__.'/../../agents/lena/post-load/skills/container-recommendation.md');
        foreach ([...$options, '4 × 40HC'] as $option) $this->assertStringContainsString($option, $skill);
        $this->assertStringNotContainsString('97% score', $skill);
    }

    public function test_chat_prompt_context_separates_planning_limits_from_carrier_facts(): void
    {
        $context = (new \App\Services\ContainerTypeCatalog)->promptContext();
        $this->assertSame(['20GP', '40HC', '40STD'], array_keys($context['planningEquipment']));
        $this->assertSame(['usableVolumeM3' => 69, 'payloadKg' => 25000], $context['planningEquipment']['40HC']);
        $this->assertArrayNotHasKey('20STD', $context['referenceSpecifications']);
        $this->assertFalse($context['referenceSpecifications']['40OT']['automaticPlanning']);
        $this->assertStringStartsWith('https://', $context['referenceSpecifications']['20GP']['source']);
    }

    public function test_specifications_enrich_existing_registered_types_without_duplicate_labels(): void
    {
        $catalog = new \App\Services\ContainerTypeCatalog;
        $schema = $catalog->applyToSchema(json_decode(file_get_contents(__DIR__.'/../../resources/lena/schema.json'), true, 512, JSON_THROW_ON_ERROR));
        $types = $schema['option_groups']['CONTAINER_TYPE_OPTIONS'];
        foreach ($catalog->planningEquipment() as $type => $specification) {
            $this->assertContains($type, $types);
            $this->assertArrayHasKey($type, $schema['container_categories']);
            $this->assertArrayNotHasKey('type', $specification);
            $this->assertArrayNotHasKey('label', $specification);
        }
        foreach ($this->recommend()['candidates'] as $candidate) {
            $this->assertContains($candidate['type'], $types);
            $this->assertSame($catalog->planningEquipment()[$candidate['type']], $candidate['capacity']);
        }
    }

    public function test_unknown_factors_are_not_fabricated_or_counted_as_zero(): void
    {
        $option = $this->recommend()['candidates'][0];
        foreach (['dimensionFit', 'palletFit', 'routeAvailability', 'carrierAvailability'] as $factor) $this->assertNull($option['factors'][$factor]);
        $this->assertSame(65, $option['coverage']);
        $this->assertSame(100, $option['score']);
        $this->assertContains('cost_proxy', $option['notes']);
    }

    public function test_whole_shipment_dimensions_and_pieces_are_not_treated_as_identical_pallets(): void
    {
        $option = $this->recommend(['lengthM' => 20, 'widthM' => 3, 'heightM' => 3, 'dimensionScope' => 'overall'])['candidates'][0];
        $this->assertSame(1, $option['quantity']);
        $this->assertNull($option['factors']['dimensionFit']);
        $this->assertNull($option['factors']['palletFit']);
    }

    public function test_non_stackable_identical_units_constrain_floor_space(): void
    {
        $option = $this->recommend(['dimensionScope' => 'per_unit', 'lengthM' => 1.2, 'widthM' => 1, 'heightM' => 1, 'quantityMeasure' => 'PX', 'pallets' => 24])['candidates'][0];
        $this->assertSame(20, $option['calculation']['unitsPerContainer']);
        $this->assertSame(2, $option['quantity']);
        $this->assertSame(100, $option['factors']['palletFit']);
    }

    public function test_high_units_fit_only_high_cube_doors(): void
    {
        $result = $this->recommend(['dimensionScope' => 'per_unit', 'lengthM' => 1, 'widthM' => 1, 'heightM' => 2.5, 'pallets' => 2]);
        $this->assertSame(['40HC'], array_column($result['candidates'], 'type'));
    }

    public function test_impossible_unit_dimensions_require_review_even_without_piece_count(): void
    {
        $this->assertSame('specialist_review', $this->recommend(['dimensionScope' => 'per_unit', 'lengthM' => 3, 'widthM' => 3, 'heightM' => 3, 'pallets' => 0])['status']);
    }

    public function test_conflicting_unit_volume_uses_the_larger_value_with_a_note(): void
    {
        $result = $this->recommend(['dimensionScope' => 'per_unit', 'lengthM' => 1, 'widthM' => 1, 'heightM' => 1, 'pallets' => 100, 'volumeM3' => 1]);
        $this->assertContains('volume_conflict', $result['warnings']);
        $this->assertEquals(100, $result['candidates'][0]['calculation']['volumeM3']);
    }

    public function test_special_cargo_never_gets_a_dry_container_recommendation(): void
    {
        foreach ([['requiresAdr' => true], ['temperatureMin' => 0], ['temperatureMax' => -18], ['cargoType' => 'DG'], ['cargoType' => 'Liquid bulk'], ['cargoType' => 'Reefer']] as $cargo) {
            $result = $this->recommend($cargo);
            $this->assertSame('specialist_review', $result['status']);
            $this->assertSame([], $result['candidates']);
        }
        $this->assertSame('estimated', $this->recommend(['characteristics' => 'Non-DG'])['status']);
    }

    public function test_missing_invalid_inputs_and_other_transport_modes(): void
    {
        foreach ([0, -1, null, INF, NAN] as $weight) $this->assertSame('insufficient_data', $this->recommend(['weightKg' => $weight])['status']);
        $this->assertSame('insufficient_data', $this->recommend(['volumeM3' => 0])['status']);
        foreach (['road', 'air', 'warehouse', ''] as $mode) $this->assertSame('not_applicable', $this->recommend(['transportType' => $mode])['status']);
        $this->assertSame('estimated', $this->recommend(['transportType' => 'rail'])['status']);
    }

    public function test_scanning_another_message_preserves_only_the_users_container_selection(): void
    {
        $reflection = new \ReflectionClass(\App\Services\OpenRouterLoadScanner::class);
        $scanner = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('mergeWithCurrent');
        $selection = [['type' => '40HC', 'quantity' => '5']];
        $merged = $method->invoke($scanner, ['weightKg' => 32000], ['weightKg' => 31000, 'containerSelections' => $selection]);
        $this->assertSame($selection, $merged['containerSelections']);
        $this->assertSame(32000, $merged['weightKg']);
        $merged = $method->invoke($scanner, ['weightKg' => 32000], ['weightKg' => 31000]);
        $this->assertArrayNotHasKey('containerSelections', $merged);
    }

    public function test_extraction_schema_requires_packaging_and_dimension_scope(): void
    {
        $reflection = new \ReflectionClass(\App\Services\OpenRouterLoadScanner::class);
        $schema = $reflection->getMethod('schema')->invoke($reflection->newInstanceWithoutConstructor());
        foreach (['quantityMeasure', 'dimensionScope'] as $field) {
            $this->assertArrayHasKey($field, $schema['properties']);
            $this->assertContains($field, $schema['required']);
        }
    }

    public function test_explicit_copy_preserves_quantity_and_legacy_picker_still_means_one(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($container);
        try {
            $catalog = $this->createMock(\App\Services\LenaCatalog::class);
            $catalog->method('schema')->willReturn(['container_categories' => ['40HC' => 'Standard', '20GP' => 'Standard']]);
            $container->instance(\App\Services\LenaCatalog::class, $catalog);
            $controller = new \App\Http\Controllers\Api\LenaGuidedAnswerController;
            $method = new \ReflectionMethod($controller, 'applyAnswer');
            $draft = $method->invoke($controller, ['weightKg' => 31000], 'containers', '40HC:5', null);
            $this->assertSame([['type' => '40HC', 'quantity' => '5']], $draft['containerSelections']);
            $this->assertSame(31000, $draft['weightKg']);
            $legacy = $method->invoke($controller, [], 'containers', '40HC,20GP', null);
            $this->assertSame(['1', '1'], array_column($legacy['containerSelections'], 'quantity'));
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
    }

    public function test_saved_draft_restores_selected_containers_and_total_volume_without_database(): void
    {
        $draft = $this->getMockBuilder(\App\Models\LoadDraft::class)->onlyMethods(['loadMissing'])->getMock();
        $draft->method('loadMissing')->willReturnSelf();
        $draft->setRawAttributes(['dimension_scope' => 'per_unit', 'volume_m3' => 1.2, 'pallets' => 24]);
        $draft->setRelation('consignee', null);
        $draft->setRelation('warehouse', null);
        $draft->container_selections = [['type' => '40HC', 'quantity' => '5']];
        $this->assertIsString($draft->getAttributes()['container_selections']);
        $scan = (new \App\Services\LoadDraftScanMapper)->toScan($draft);
        $this->assertEqualsWithDelta(28.8, $scan['volumeM3'], 0.000001);
        $this->assertSame('5', $scan['containerSelections'][0]['quantity']);
    }
}
