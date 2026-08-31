<?php

namespace App\Console\Commands;

use App\Models\HsCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

// The 2026_08_28 migration replaced the old HS-2022 catalog with the tariff-code schema but ships
// no rows of its own, so the table stays empty until this command loads the customs export into it.
class ImportTariffCatalog extends Command
{
    protected $signature = 'tariffs:import
        {--file= : Path to the tariff JSON export (defaults to database/data/Tarife_DE_final.json)}
        {--fresh : Replace the catalog - required when it already holds rows}
        {--chunk=500 : Rows per insert}';

    protected $description = 'Import the customs tariff catalog (hs_code_catalog) from the Tarife JSON export';

    /** Export column -> table column. The export ships Bosnian headers. */
    private const COLUMNS = [
        'EX' => 'ex',
        'Tarifna oznaka' => 'tariff_code',
        'Naziv' => 'name',
        'Odjeljak' => 'section',
        'Glava' => 'chapter',
        'Prethodna tarifna oznaka' => 'previous_tariff_code',
        'Puni Naziv' => 'full_name',
        'Puni Naziv - ENG' => 'full_name_en',
        'Puni Naziv - D' => 'full_name_de',
    ];

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: database_path('data/Tarife_DE_final.json'));
        if (! is_readable($path)) {
            $this->error("Tariff export is missing or unreadable: {$path}");

            return self::FAILURE;
        }

        $table = (new HsCode)->getTable();
        $existing = DB::table($table)->count();
        if ($existing > 0 && ! $this->option('fresh')) {
            $this->error("{$table} already holds {$existing} rows. Re-run with --fresh to replace them.");

            return self::FAILURE;
        }

        try {
            $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Tariff export is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($rows) || $rows === []) {
            $this->error('Tariff export contains no rows.');

            return self::FAILURE;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $imported = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        DB::transaction(function () use ($table, $rows, $chunk, $bar, &$imported, &$skipped): void {
            if ($existingRows = DB::table($table)->count()) {
                $this->newLine();
                $this->line("Replacing {$existingRows} existing rows.");
                DB::table($table)->delete();
            }

            $batch = [];
            foreach ($rows as $row) {
                $bar->advance();
                if (! is_array($row)) {
                    $skipped++;
                    continue;
                }

                $record = [];
                foreach (self::COLUMNS as $source => $column) {
                    $value = $row[$source] ?? null;
                    $record[$column] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
                }
                // A row with no tariff code can never be searched or selected, so it is not worth storing.
                if ($record['tariff_code'] === null) {
                    $skipped++;
                    continue;
                }

                $batch[] = $record;
                if (count($batch) >= $chunk) {
                    DB::table($table)->insert($batch);
                    $imported += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table($table)->insert($batch);
                $imported += count($batch);
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$imported} tariff rows into {$table}." . ($skipped > 0 ? " Skipped {$skipped} without a tariff code." : ''));

        return self::SUCCESS;
    }
}
