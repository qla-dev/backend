<?php

namespace App\Console\Commands;

use App\Models\FuelStation;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

class ImportFueloFuelStations extends Command
{
    protected $signature = 'fuel-stations:import-fuelo
        {south : Southern latitude}
        {west : Western longitude}
        {north : Northern latitude}
        {east : Eastern longitude}
        {--zoom=14 : Fuelo map zoom level}
        {--step=0.3 : Tile size in latitude/longitude degrees}
        {--delay=1.5 : Delay between tile requests in seconds}
        {--input= : Import a saved JSON array or NDJSON file instead of crawling Fuelo}';

    protected $description = 'Import and update Fuelo fuel stations inside a bounding box';

    private int $imported = 0;

    /** @var array<int, array<string, mixed>> */
    private array $pendingRows = [];

    public function handle(): int
    {
        $south = (float) $this->argument('south');
        $west = (float) $this->argument('west');
        $north = (float) $this->argument('north');
        $east = (float) $this->argument('east');
        $zoom = (int) $this->option('zoom');
        $step = (float) $this->option('step');
        $delay = (float) $this->option('delay');

        if (! $this->validBounds($south, $west, $north, $east)) {
            $this->error('Invalid bounding box. Expected south < north and west < east.');

            return self::FAILURE;
        }

        if ($zoom < 1 || $zoom > 22 || $step <= 0 || $step > 5 || $delay < 0) {
            $this->error('Expected zoom 1-22, step greater than 0 and at most 5, and a non-negative delay.');

            return self::FAILURE;
        }

        $tileCount = (int) ceil(($north - $south) / $step) * (int) ceil(($east - $west) / $step);
        if ($tileCount > 10_000) {
            $this->error("The selected bounds would generate {$tileCount} requests; increase --step or use a smaller region.");

            return self::FAILURE;
        }

        $input = $this->option('input');
        if (is_string($input) && $input !== '') {
            return $this->importFile($input);
        }

        $script = base_path('scripts/fetch_fuelo_stations.py');
        if (! is_file($script)) {
            $this->error("Fuelo fetcher not found: {$script}");

            return self::FAILURE;
        }

        $this->info("Crawling {$tileCount} Fuelo map tiles...");

        $process = new Process([
            (string) config('services.fuelo.python_binary', 'python3'),
            $script,
            '--south', (string) $south,
            '--west', (string) $west,
            '--north', (string) $north,
            '--east', (string) $east,
            '--zoom', (string) $zoom,
            '--step', (string) $step,
            '--delay', (string) $delay,
            '--base-url', (string) config('services.fuelo.base_url'),
            '--endpoint', (string) config('services.fuelo.stations_url'),
        ]);
        $process->setTimeout(null);

        $stdoutBuffer = '';
        $stderrBuffer = '';
        $fetchCompleted = false;
        try {
            $process->run(function (string $type, string $buffer) use (&$stdoutBuffer, &$stderrBuffer, &$fetchCompleted): void {
                if ($type === Process::OUT) {
                    $stdoutBuffer .= $buffer;
                    $this->consumeCompleteLines($stdoutBuffer);

                    return;
                }

                $stderrBuffer .= $buffer;
                while (($newline = strpos($stderrBuffer, "\n")) !== false) {
                    $line = trim(substr($stderrBuffer, 0, $newline));
                    $stderrBuffer = substr($stderrBuffer, $newline + 1);
                    if (str_starts_with($line, 'FUELO_FETCH_COMPLETE ')) {
                        $fetchCompleted = true;

                        continue;
                    }
                    if ($line !== '' && $this->output->isVerbose()) {
                        $this->line($line);
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->error('Could not start the Fuelo fetcher: '.$exception->getMessage());
            $this->line('Install Python and run: pip install -r scripts/requirements-fuelo.txt');

            return self::FAILURE;
        }

        $this->consumeLine(trim($stdoutBuffer));
        $this->flushRows();

        if (! $process->isSuccessful()) {
            $error = trim($stderrBuffer) ?: trim($process->getErrorOutput());
            $this->error($error !== '' ? $error : 'The Fuelo fetcher failed.');
            if ($this->imported > 0) {
                $this->warn("{$this->imported} stations were saved before the fetcher stopped.");
            }

            return self::FAILURE;
        }

        if (! $fetchCompleted) {
            $this->error('The Fuelo fetcher exited without completing. Verify Python and the cloudscraper dependency.');
            $this->line('Install the dependency with: pip install -r scripts/requirements-fuelo.txt');

            return self::FAILURE;
        }

        $this->info("Imported or updated {$this->imported} Fuelo fuel stations.");

        return self::SUCCESS;
    }

    private function importFile(string $path): int
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Input file is not readable: {$path}");

            return self::FAILURE;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->error("Could not read input file: {$path}");

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $items = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $this->queueItem($item);
                }
            }
        } catch (JsonException) {
            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $this->consumeLine(trim($line));
            }
        }

        $this->flushRows();
        $this->info("Imported or updated {$this->imported} Fuelo fuel stations.");

        return self::SUCCESS;
    }

    private function consumeCompleteLines(string &$buffer): void
    {
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $newline));
            $buffer = substr($buffer, $newline + 1);
            $this->consumeLine($line);
        }
    }

    private function consumeLine(string $line): void
    {
        if ($line === '') {
            return;
        }

        try {
            $item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->warn('Ignored an invalid JSON record from the Fuelo fetcher.');

            return;
        }

        if (is_array($item)) {
            $this->queueItem($item);
        }
    }

    /** @param array<string, mixed> $item */
    private function queueItem(array $item): void
    {
        $row = $this->mapStation($item);
        if ($row === null) {
            return;
        }

        $this->pendingRows[] = $row;
        $this->imported++;

        if (count($this->pendingRows) >= 500) {
            $this->flushRows();
        }
    }

    private function flushRows(): void
    {
        if ($this->pendingRows === []) {
            return;
        }

        FuelStation::query()->upsert(
            $this->pendingRows,
            ['source', 'source_type', 'source_id'],
            ['name', 'brand', 'operator', 'address', 'latitude', 'longitude', 'opening_hours', 'hgv', 'fuel_types', 'raw_payload', 'source_updated_at', 'last_synced_at', 'updated_at'],
        );
        $this->pendingRows = [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function mapStation(array $item): ?array
    {
        $sourceId = $item['id'] ?? $item['gasstation_id'] ?? null;
        $latitude = $item['lat'] ?? $item['latitude'] ?? $item['y'] ?? $item['avglat'] ?? null;
        $longitude = $item['lng'] ?? $item['lon'] ?? $item['longitude'] ?? $item['x'] ?? $item['avglon'] ?? null;

        if (! is_scalar($sourceId) || trim((string) $sourceId) === '' || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        $syncedAt = now();

        return [
            'source' => 'fuelo',
            'source_type' => 'station',
            'source_id' => trim((string) $sourceId),
            'name' => $this->text($item['name'] ?? $item['title'] ?? null),
            'brand' => $this->text($item['brand'] ?? $item['brand_name'] ?? null),
            'operator' => $this->text($item['operator'] ?? null),
            'address' => $this->text($item['address'] ?? $item['address_string'] ?? null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'opening_hours' => $this->text($item['opening_hours'] ?? null),
            'hgv' => $this->nullableBoolean($item['hgv'] ?? null),
            'fuel_types' => isset($item['fuel_types']) && is_array($item['fuel_types'])
                ? json_encode(array_values($item['fuel_types']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'source_updated_at' => $this->date($item['updated_at'] ?? $item['last_update'] ?? null),
            'last_synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private function date(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function validBounds(float $south, float $west, float $north, float $east): bool
    {
        return $south >= -90 && $north <= 90 && $west >= -180 && $east <= 180
            && $south < $north && $west < $east;
    }
}
