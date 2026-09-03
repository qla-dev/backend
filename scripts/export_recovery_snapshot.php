<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$output = $argv[1] ?? null;
if (! is_string($output) || trim($output) === '') {
    fwrite(STDERR, 'Usage: php scripts/export_recovery_snapshot.php <output.jsonl.gz>'.PHP_EOL);
    exit(2);
}

$tables = [
    'hs_code_catalog',
    'customers',
    'loads',
    'load_stops',
    'shipments',
    'routes',
    'route_stops',
];

$handle = gzopen($output, 'wb9');
if ($handle === false) {
    fwrite(STDERR, "Cannot open snapshot path: {$output}".PHP_EOL);
    exit(2);
}

$connection = DB::connection();
$meta = [
    'type' => 'meta',
    'created_at' => now()->toIso8601String(),
    'driver' => $connection->getDriverName(),
    'database' => $connection->getDatabaseName(),
    'tables' => $tables,
];
gzwrite($handle, json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");

$counts = [];
foreach ($tables as $table) {
    $counts[$table] = 0;
    foreach (DB::table($table)->orderBy('id')->lazyById(500) as $row) {
        gzwrite($handle, json_encode([
            'type' => 'row',
            'table' => $table,
            'data' => (array) $row,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $counts[$table]++;
    }
}

gzwrite($handle, json_encode(['type' => 'complete', 'counts' => $counts], JSON_THROW_ON_ERROR)."\n");
gzclose($handle);

echo json_encode(['output' => realpath($output), 'counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
