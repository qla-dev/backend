<?php

namespace Tests\Unit;

use App\Services\ContainerTypeCatalog;
use PHPUnit\Framework\TestCase;

class ContainerTypeCatalogTest extends TestCase
{
    public function test_all_existing_codes_keep_their_order_and_have_sourced_specs_or_an_alias(): void
    {
        $types = (new ContainerTypeCatalog)->data()['types'];
        $this->assertSame(['20GP', '20STD', '40HC', '40STD', '20OT', '40OT', '40OTHC', '20RF', '40RH', '45RH', '20FR', '40FR', '40FRHC', '20PL', '40PL'], array_keys($types));
        foreach ($types as $code => $type) {
            if (isset($type['aliasOf'])) {
                $this->assertNotSame($code, $type['aliasOf']);
                $this->assertArrayHasKey($type['aliasOf'], $types);
                $this->assertSame($type['category'], $types[$type['aliasOf']]['category']);
                $this->assertArrayNotHasKey('aliasOf', $types[$type['aliasOf']]);
                $this->assertArrayNotHasKey('planning', $type);
                continue;
            }
            $spec = $type['specifications'];
            $this->assertSame('https', parse_url($spec['source'], PHP_URL_SCHEME));
            $this->assertSame('2026-09-13', $spec['checkedAt']);
            $this->assertSame($spec['maxGrossKg'], $spec['tareKg'] + $spec['maxPayloadKg']);
            $this->assertGreaterThan(0, $spec['lengthM']);
            $this->assertGreaterThan(0, $spec['widthM']);
            if ($type['category'] === 'Platform') {
                $this->assertNull($spec['heightM']);
                $this->assertNull($spec['nominalVolumeM3']);
                $this->assertNull($spec['doorWidthM']);
            }
            if (isset($type['planning'])) {
                $this->assertLessThanOrEqual($spec['maxPayloadKg'], $type['planning']['payloadKg']);
                $this->assertLessThanOrEqual($spec['nominalVolumeM3'], $type['planning']['usableVolumeM3']);
            }
        }
    }

    public function test_schema_composition_keeps_existing_consumer_contract_and_owns_container_icons(): void
    {
        $raw = json_decode(file_get_contents(__DIR__.'/../../resources/lena/schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('CONTAINER_TYPE_OPTIONS', $raw['option_groups']);
        $this->assertArrayNotHasKey('container_categories', $raw);
        $catalog = new ContainerTypeCatalog;
        $schema = $catalog->applyToSchema($raw);
        $types = $catalog->data()['types'];
        $this->assertSame(array_keys($types), $schema['option_groups']['CONTAINER_TYPE_OPTIONS']);
        foreach ($types as $code => $type) {
            $this->assertSame($type['category'], $schema['container_categories'][$code]);
            $this->assertSame($type['icon'], $schema['option_icons'][$code]);
            $this->assertArrayNotHasKey($code, $raw['option_icons']);
        }
        $this->assertSame($raw['steps'], $schema['steps']);
    }

    public function test_researched_special_equipment_and_aliases_do_not_enable_dry_scoring(): void
    {
        $catalog = new ContainerTypeCatalog;
        $this->assertSame(['20GP', '40HC', '40STD'], array_keys($catalog->planningEquipment()));
        $this->assertSame('20GP', $catalog->data()['types']['20STD']['aliasOf']);
        $this->assertNotEmpty($catalog->data()['types']['45RH']['specifications']['notes']);
    }
}
