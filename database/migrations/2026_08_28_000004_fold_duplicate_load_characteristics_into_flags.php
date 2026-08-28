<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The post-load form used to offer ADR / CMR / Lift / Express (and DG / DGR / Non-DG / REEFER) as
// free-text "characteristics" while ALSO offering the same statement as a requirement toggle backed
// by a real column. The duplicates are gone from the form; this folds what customers already saved
// into the column that survives, so nothing is lost when the free-text value disappears.
return new class extends Migration
{
    /** characteristic value => boolean column it is really stating. */
    private const FOLD_INTO = [
        'ADR' => 'requires_adr',
        'DG' => 'requires_adr',
        'DGR' => 'requires_adr',
        'CMR' => 'cmr_required',
        'Lift' => 'requires_tail_lift',
        'Express' => 'is_urgent',
    ];

    /** Removed without a home: the temperature range and a false ADR flag already say this. */
    private const DROP_ONLY = ['Non-DG', 'REEFER'];

    public function up(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            $rows = DB::table($table)
                ->whereNotNull('characteristics')
                ->where('characteristics', '<>', '')
                ->select('id', 'characteristics')
                ->get();

            foreach ($rows as $row) {
                $values = $this->parse($row->characteristics);
                $removed = array_values(array_filter(
                    $values,
                    fn (string $value) => isset(self::FOLD_INTO[$value]) || in_array($value, self::DROP_ONLY, true),
                ));

                if ($removed === []) {
                    continue;
                }

                $update = [];
                foreach ($removed as $value) {
                    if (isset(self::FOLD_INTO[$value])) {
                        $update[self::FOLD_INTO[$value]] = true;
                    }
                }

                $kept = array_values(array_diff($values, $removed));
                $update['characteristics'] = $kept === [] ? null : json_encode($kept);

                DB::table($table)->where('id', $row->id)->update($update);
            }
        }
    }

    // Irreversible by design: once ADR lives in requires_adr there is no way to tell whether the
    // flag came from the old free-text value or from the toggle, so putting the strings back would
    // invent data.
    public function down(): void {}

    /** @return list<string> */
    private function parse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $values = is_array($decoded) ? $decoded : explode(',', $raw);

        return array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $values,
        ), fn (string $value) => $value !== ''));
    }
};
