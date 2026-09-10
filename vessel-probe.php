<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'logging.default' => 'stderr']);
foreach ([App\Services\OpenWatersVesselClient::class, App\Services\AisVesselStreamClient::class] as $client) {
    try {
        $service = app($client);
        $rows = $service instanceof App\Services\AisVesselStreamClient
            ? $service->capture(-90, -180, 90, 180, 8, ['249533000'])
            : $service->capture(-90, -180, 90, 180, ['249533000']);
        echo json_encode(['client' => $client, 'rows' => $rows]).PHP_EOL;
    } catch (Throwable $e) { echo json_encode(['client' => $client, 'error' => $e->getMessage()]).PHP_EOL; }
}
