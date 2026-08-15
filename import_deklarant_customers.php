<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$source = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        getenv('EXPORT_DB_HOST'),
        getenv('EXPORT_DB_PORT'),
        getenv('EXPORT_DB_NAME'),
    ),
    getenv('EXPORT_DB_USER'),
    getenv('EXPORT_DB_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);
$source->exec('SET TRANSACTION READ ONLY');

$duplicateTaxNumbers = (int) $source->query(<<<'SQL'
    SELECT count(*)
    FROM (
        SELECT tax_id
        FROM public.importers
        WHERE (tax_id <> '010000000019' OR tax_id IS NULL)
          AND tax_id IS NOT NULL
        GROUP BY tax_id
        HAVING count(*) > 1
    ) duplicates
    SQL)->fetchColumn();

echo "duplicate_tax_numbers={$duplicateTaxNumbers}\n";

if ($duplicateTaxNumbers > 0) {
    exit(3);
}

$statement = $source->query(<<<'SQL'
    SELECT
        id,
        name,
        tax_id,
        contact_email,
        contact_phone,
        address_country,
        address_city,
        address_street_name,
        address_street_number,
        created_at,
        updated_at
    FROM public.importers
    WHERE tax_id <> '010000000019' OR tax_id IS NULL
    ORDER BY id
    SQL);

$rows = [];
$processed = 0;

$save = static function (array $rows): void {
    DB::table('customers')->upsert(
        $rows,
        ['source', 'source_id'],
        [
            'name',
            'email',
            'phone',
            'country_code',
            'company_name',
            'tax_number',
            'billing_email',
            'billing_address',
            'city',
            'updated_at',
        ],
    );
};

while ($importer = $statement->fetch()) {
    $address = trim(implode(' ', array_filter([
        $importer['address_street_name'],
        $importer['address_street_number'],
    ], static fn ($value): bool => $value !== null && $value !== '')));

    $rows[] = [
        'user_id' => null,
        'name' => $importer['name'],
        'email' => $importer['contact_email'],
        'phone' => $importer['contact_phone'],
        'country_code' => $importer['address_country'] ?: null,
        'source' => 'deklarant.ai',
        'source_id' => $importer['id'],
        'profile_authorized_at' => null,
        'customer_type' => 'business',
        'status' => 'active',
        'company_name' => $importer['name'],
        'tax_number' => $importer['tax_id'],
        'billing_email' => $importer['contact_email'],
        'billing_address' => $address !== '' ? $address : null,
        'city' => $importer['address_city'],
        'created_at' => $importer['created_at'] ?: now(),
        'updated_at' => $importer['updated_at'] ?: now(),
    ];
    $processed++;

    if (count($rows) === 500) {
        $save($rows);
        $rows = [];
    }
}

if ($rows !== []) {
    $save($rows);
}

echo "processed={$processed}\n";
echo 'imported=', DB::table('customers')->where('source', 'deklarant.ai')->count(), "\n";
echo 'authorized=', DB::table('customers')->where('source', 'deklarant.ai')->whereNotNull('profile_authorized_at')->count(), "\n";
echo 'linked_users=', DB::table('customers')->where('source', 'deklarant.ai')->whereNotNull('user_id')->count(), "\n";
