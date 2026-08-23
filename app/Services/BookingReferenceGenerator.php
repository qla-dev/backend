<?php

namespace App\Services;

use App\Models\BookingReferenceSequence;
use Illuminate\Support\Facades\DB;

// Format: FB-{type}-{YY}{NNN} - e.g. FB-C-26000, FB-L-26014, FB-R-26003, FB-X-26000.
// C = road, L = sea, R = air, X = transport type not decided yet (drafts start here and get
// upgraded to a real typed reference the first time transport type is actually set - see
// LoadDraft::booted()). Each (type, year) has its own zero-based sequence that resets every
// January under a fresh year value; nothing is shared across type codes.
class BookingReferenceGenerator
{
    private const TYPE_CODES = [
        'road' => 'C',
        'sea' => 'L',
        'air' => 'R',
    ];

    public function generate(?string $transportType): string
    {
        $typeCode = self::TYPE_CODES[$transportType] ?? 'X';
        $year = (int) now()->format('y');

        $value = DB::transaction(function () use ($typeCode, $year): int {
            BookingReferenceSequence::query()->firstOrCreate(
                ['type_code' => $typeCode, 'year' => $year],
                ['next_value' => 0]
            );

            $sequence = BookingReferenceSequence::query()
                ->where('type_code', $typeCode)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $value = $sequence->next_value;
            $sequence->update(['next_value' => $value + 1]);

            return $value;
        });

        return sprintf('FB-%s-%02d%03d', $typeCode, $year, $value);
    }
}
