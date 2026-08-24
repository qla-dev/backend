<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            if (! Schema::hasColumn($table, 'booking_reference')) {
                continue;
            }

            DB::table($table)
                ->select(['id', 'booking_reference'])
                ->where('booking_reference', 'like', 'FB-%')
                ->orderBy('id')
                ->chunkById(500, function ($records) use ($table): void {
                    $ids = $records
                        ->filter(fn ($record): bool => preg_match('/^FB-[CRLX]-\d{5}$/', (string) $record->booking_reference) === 1)
                        ->pluck('id');

                    if ($ids->isNotEmpty()) {
                        DB::table($table)->whereIn('id', $ids)->update(['booking_reference' => null]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Generated tracking numbers cannot be safely restored as external booking references.
    }
};
