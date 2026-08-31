<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

// Loads the deklarant.ba consignee list (public.importers, exported to database/data) into
// customers as standalone records - no user account, so they behave exactly like a customer typed
// in by hand. Keyed on (source, source_id), which the customers table already has a unique index
// for, so re-running updates the existing rows instead of duplicating them.
class ImportDeklarantCustomers extends Command
{
    protected $signature = 'customers:import-deklarant
        {--file= : Path to the exported JSON (defaults to database/data/deklarant_primatelji.json)}
        {--limit=0 : Import at most this many rows (0 imports everything)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Import deklarant.ba consignees (primatelji) as standalone customers';

    private const SOURCE = 'deklarant';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: database_path('data/deklarant_primatelji.json'));
        if (! is_readable($path)) {
            $this->error("Consignee export is missing or unreadable: {$path}");

            return self::FAILURE;
        }

        try {
            $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Consignee export is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($rows) || $rows === []) {
            $this->error('Consignee export contains no rows.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        // The database is remote, so this batches rather than issuing two round trips per row:
        // one upsert per chunk, keyed on the (source, source_id) unique index, which inserts new
        // consignees and refreshes ones already imported in the same statement.
        $now = now();
        $batch = [];
        $flush = function () use (&$batch, $dryRun, &$created): void {
            if ($batch === []) {
                return;
            }
            if (! $dryRun) {
                DB::table('customers')->upsert(
                    $batch,
                    ['source', 'source_id'],
                    ['name', 'customer_type', 'status', 'company_name', 'tax_number', 'email',
                        'billing_email', 'phone', 'country_code', 'city', 'billing_address', 'updated_at'],
                );
            }
            $created += count($batch);
            $batch = [];
        };

        foreach ($rows as $row) {
            $bar->advance();
            $sourceId = isset($row['id']) ? (int) $row['id'] : 0;
            $name = $this->clean($row['name'] ?? null);
            // A consignee with no name or no source id cannot be identified or displayed.
            if ($sourceId === 0 || $name === null) {
                $skipped++;
                continue;
            }

            $country = $this->clean($row['address_country'] ?? null);
            $street = trim(implode(' ', array_filter([
                $this->clean($row['address_street_name'] ?? null),
                $this->clean($row['address_street_number'] ?? null),
            ])));
            $email = $this->clean($row['contact_email'] ?? null);

            $batch[] = [
                'source' => self::SOURCE,
                'source_id' => $sourceId,
                'name' => $name,
                // Every consignee in this list is a registered business, not a private person.
                'customer_type' => 'business',
                'status' => 'active',
                'company_name' => $name,
                'tax_number' => $this->clean($row['tax_id'] ?? null),
                'email' => $email,
                'billing_email' => $email,
                'phone' => $this->clean($row['contact_phone'] ?? null),
                'country_code' => $country !== null && strlen($country) === 2 ? strtoupper($country) : null,
                'city' => $this->clean($row['address_city'] ?? null),
                'billing_address' => $street !== '' ? $street : null,
                // Imported records are not authorized profiles - they have no user account behind
                // them, exactly like a customer entered by hand.
                'user_id' => null,
                'profile_authorized_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                $flush();
            }
        }

        $flush();

        $bar->finish();
        $this->newLine(2);
        $this->info(($dryRun ? '[dry run] ' : '')."Wrote {$created} consignees, skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
