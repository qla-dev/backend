<?php

namespace App\Services;

use App\Models\TrackingNumberSequence;
use Illuminate\Support\Facades\DB;

// Freightbook shipment tracking format: FB-{C|R|L|Z}-{YY}{NNN}.
// C = road, R = air, L = sea, Z = rail.
// Each transport type has its own zero-based yearly sequence: FB-C-26000, FB-C-26001, ...
class TrackingNumberGenerator
{
    private const TYPE_CODES = [
        'road' => 'C',
        'sea' => 'L',
        'air' => 'R',
        'rail' => 'Z',
    ];

    public function generate(?string $transportType): string
    {
        return $this->generateBatch($transportType, 1)[0];
    }

    /** @return list<string> */
    public function generateBatch(?string $transportType, int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Tracking number batch size must be positive.');
        }

        $typeCode = self::TYPE_CODES[$transportType]
            ?? throw new \InvalidArgumentException('A valid shipment transport type is required.');
        $year = (int) now()->format('y');

        $firstValue = DB::transaction(function () use ($typeCode, $year, $count): int {
            TrackingNumberSequence::query()->firstOrCreate(
                ['type_code' => $typeCode, 'year' => $year],
                ['next_value' => 0]
            );

            $sequence = TrackingNumberSequence::query()
                ->where('type_code', $typeCode)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $value = $sequence->next_value;
            $sequence->update(['next_value' => $value + $count]);

            return $value;
        });

        return array_map(
            fn (int $value): string => sprintf('FB-%s-%02d%03d', $typeCode, $year, $value),
            range($firstValue, $firstValue + $count - 1),
        );
    }
}
