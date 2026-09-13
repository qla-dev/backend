<?php

namespace App\Services;

/** Pure calculation shared by the canvas and Lena. No database or model calls. */
class ContainerRecommendationEngine
{
    public function recommend(array $cargo): array
    {
        $catalog = new ContainerTypeCatalog;
        $config = $catalog->data()['planning'];
        $result = ['version' => $config['version'], 'status' => 'insufficient_data', 'warnings' => [], 'candidates' => []];
        if (! in_array($cargo['transportType'] ?? '', ['sea', 'rail'], true)) {
            return [...$result, 'status' => 'not_applicable'];
        }
        $weight = $this->positive($cargo['weightKg'] ?? null);
        $volume = $this->positive($cargo['volumeM3'] ?? null);
        if (! $weight || ! $volume) {
            return [...$result, 'warnings' => ['weight_volume_required']];
        }

        $description = strtolower(implode(' ', array_filter([
            $cargo['cargoType'] ?? '', $cargo['goodsType'] ?? '', $cargo['characteristics'] ?? '',
            implode(' ', $cargo['specialRequirements'] ?? []),
        ])));
        // HS describes goods, not handling requirements. Never infer DG clearance or temperature
        // suitability from a tariff code alone. Unclassified/special cargo needs operator review.
        $special = ($cargo['requiresAdr'] ?? false)
            || ($cargo['temperatureMin'] ?? null) !== null || ($cargo['temperatureMax'] ?? null) !== null
            || preg_match('/reefer|refriger|frozen|chilled|temperature|danger|hazard|\badr\b|(?<!non-)\bdg\b|\bimo\b|out.of.gauge|oversiz|liquid|bulk|tank|rashlad|smrzn|opasn|tečn|hlad|kühl|gefahr|flüss/u', $description);
        if ($special) {
            return [...$result, 'status' => 'specialist_review', 'warnings' => ['special_equipment_review']];
        }
        $general = preg_match('/\bgeneral\b|\bdry\b|opć|opc|suha|suhi|allgemein|trocken|stückgut/u', $description) === 1;
        $length = $this->positive($cargo['lengthM'] ?? null);
        $width = $this->positive($cargo['widthM'] ?? null);
        $height = $this->positive($cargo['heightM'] ?? null);
        $units = $this->positive($cargo['pallets'] ?? null);
        $perUnit = ($cargo['dimensionScope'] ?? '') === 'per_unit';
        $shapeKnown = $perUnit && $length && $width && $height;
        $dimensionsKnown = $shapeKnown && $units;
        $packaging = strtolower((string) ($cargo['quantityMeasure'] ?? ''));
        $isPallet = preg_match('/pallet|palet|palette|^px$/', $packaging) === 1;
        $warnings = ['estimate_only', 'availability_unknown', 'cost_proxy'];
        if (! $dimensionsKnown) $warnings[] = 'unit_dimensions_unknown';
        if (! $general) $warnings[] = 'cargo_compatibility_unknown';
        if (! $isPallet || ! $dimensionsKnown) $warnings[] = 'pallet_fit_unknown';
        if (! empty($cargo['hsCodes'])) $warnings[] = 'hs_not_handling_proof';
        if ($dimensionsKnown && $length * $width * $height * $units > $volume + 0.001) {
            $volume = $length * $width * $height * $units;
            $warnings[] = 'volume_conflict';
        }

        $candidates = [];
        foreach ($catalog->planningEquipment() as $type => $equipment) {
            $byVolume = (int) ceil($volume / $equipment['usableVolumeM3']);
            $byWeight = (int) ceil($weight / $equipment['payloadKg']);
            $byUnits = 0;
            $slots = null;
            if ($shapeKnown) {
                // Upright cargo only; allow horizontal rotation. Never assume stackability.
                // A uniform floor grid is conservative and is not a 3D bin-packing guarantee.
                $slots = 0;
                foreach ([[$length, $width], [$width, $length]] as [$l, $w]) {
                    if ($w <= $equipment['doorWidthM'] && $height <= $equipment['doorHeightM']
                        && $height <= $equipment['heightM'] && (! $units || $weight / $units <= $equipment['payloadKg'])) {
                        $slots = max($slots, (int) floor($equipment['lengthM'] / $l) * (int) floor($equipment['widthM'] / $w));
                    }
                }
                if ($slots < 1) continue;
                $byUnits = $units ? (int) ceil($units / $slots) : 0;
            }
            $quantity = max(1, $byVolume, $byWeight, $byUnits);
            $candidates[] = [
                'type' => $type, 'label' => $type, 'quantity' => $quantity,
                'capacity' => $equipment,
                'calculation' => ['weightKg' => $weight, 'volumeM3' => $volume, 'byVolume' => $byVolume, 'byWeight' => $byWeight, 'byUnits' => $byUnits, 'unitsPerContainer' => $slots],
                'volumeUtilization' => round(100 * $volume / ($quantity * $equipment['usableVolumeM3']), 1),
                'weightUtilization' => round(100 * $weight / ($quantity * $equipment['payloadKg']), 1),
                'factors' => ['volumeFit' => 100, 'weightFit' => 100, 'dimensionFit' => $dimensionsKnown ? 100 : null,
                    'palletFit' => $isPallet && $dimensionsKnown ? 100 : null, 'cargoCompatibility' => $general ? 100 : null,
                    'routeAvailability' => null, 'carrierAvailability' => null, 'costEfficiency' => null],
            ];
        }
        if (! $candidates) return [...$result, 'status' => 'specialist_review', 'warnings' => ['no_dimension_fit']];
        $minimum = min(array_column($candidates, 'quantity'));
        foreach ($candidates as &$candidate) {
            // Explicit container-count proxy until comparable carrier quotes are available.
            $candidate['factors']['costEfficiency'] = round(100 * $minimum / $candidate['quantity']);
            $weighted = $knownWeight = 0;
            foreach ($candidate['factors'] as $key => $score) {
                if ($score === null) continue;
                $weighted += $score * $config['weights'][$key];
                $knownWeight += $config['weights'][$key];
            }
            $candidate['score'] = (int) round($weighted / $knownWeight);
            $candidate['coverage'] = $knownWeight;
            $candidate['weights'] = $config['weights'];
            $candidate['notes'] = $warnings;
            $candidate['reasons'] = ['volume_sufficient', 'weight_within_limit'];
            if ($general) $candidate['reasons'][] = 'general_cargo_suitable';
            if ($dimensionsKnown) $candidate['reasons'][] = 'upright_floor_fit';
            $candidate['reasons'][] = $candidate['quantity'] === $minimum ? 'minimum_container_count' : 'more_containers';
        }
        unset($candidate);
        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['quantity'] <=> $b['quantity']) ?: ($b['capacity']['usableVolumeM3'] <=> $a['capacity']['usableVolumeM3']));
        foreach ($candidates as $index => &$candidate) {
            if ($index > 0 && $candidate['quantity'] === $candidates[0]['quantity']) $candidate['reasons'][] = 'less_volume_headroom';
        }
        unset($candidate);

        return [...$result, 'status' => 'estimated', 'warnings' => $warnings, 'candidates' => $candidates];
    }

    private function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }
}
