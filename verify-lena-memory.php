<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = config('database.default');
$config = config('database.connections.'.$name);
$actual = $app['db']->connection()->getConfig();
echo json_encode(['connection'=>$name, 'driver'=>$actual['driver'] ?? null, 'host'=>$actual['host'] ?? null, 'port'=>$actual['port'] ?? null, 'database'=>$actual['database'] ?? null, 'cached'=>$app->configurationIsCached()]);
if (($actual['driver'] ?? null) !== 'sqlite' || ($actual['database'] ?? null) !== ':memory:') exit(2);
