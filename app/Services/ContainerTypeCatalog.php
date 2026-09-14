<?php

namespace App\Services;

/** Shared equipment registry. Reading it does not boot Laravel or access the database. */
class ContainerTypeCatalog
{
    public function data(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/lena/container-types.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Preserve the existing form/catalog API while keeping definitions in one file. */
    public function applyToSchema(array $schema): array
    {
        $types = $this->data()['types'];
        $schema['option_groups']['CONTAINER_TYPE_OPTIONS'] = array_keys($types);
        $schema['container_categories'] = array_map(fn ($type) => $type['category'], $types);
        foreach ($types as $code => $type) $schema['option_icons'][$code] = $type['icon'];
        $schema['container_types'] = $types;

        return $schema;
    }

    /** Only equipment with explicit operational assumptions is enabled for dry-cargo planning. */
    public function planningEquipment(): array
    {
        $equipment = [];
        foreach ($this->data()['types'] as $code => $type) {
            if (isset($type['aliasOf']) || $type['category'] !== 'Standard' || empty($type['planning'])) continue;
            $specification = $type['specifications'];
            $equipment[$code] = [
                ...$type['planning'],
                ...array_intersect_key($specification, array_flip(['lengthM', 'widthM', 'heightM', 'doorWidthM', 'doorHeightM', 'source'])),
            ];
        }

        return $equipment;
    }

    /** Catalogue facts for chat answers without a load draft: planning limits beside sourced carrier reference data. */
    public function promptContext(): array
    {
        $data = $this->data();
        $planning = $this->planningEquipment();
        $reference = [];
        foreach ($data['types'] as $code => $type) {
            if (isset($type['aliasOf']) || empty($type['specifications'])) continue;
            $specification = array_diff_key($type['specifications'], array_flip(['status', 'notes']));
            $reference[$code] = ['category' => $type['category'], ...$specification, 'automaticPlanning' => isset($planning[$code])];
        }

        return ['version' => $data['planning']['version'], 'weights' => $data['planning']['weights'],
            'planningEquipment' => array_map(fn ($equipment) => array_intersect_key($equipment, array_flip(['usableVolumeM3', 'payloadKg'])), $planning),
            'referenceSpecifications' => $reference];
    }
}
