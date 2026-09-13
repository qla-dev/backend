<?php
// Read-only diagnostics. Never invokes speech generation, logging, or reconciliation.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
echo json_encode(['connection' => array_intersect_key($connection->getConfig(), array_flip(['driver', 'host', 'port', 'database']))], JSON_UNESCAPED_UNICODE).PHP_EOL;
$logs = $connection->table('ai_call_logs')->where('service', 'speech')->orderByDesc('id')->limit(12)
    ->get(['id', 'conversation_id', 'created_at', 'http_status', 'is_success', 'error_message', 'generation_id', 'response_payload']);
foreach ($logs as $row) {
    $payload = json_decode($row->response_payload ?? '{}', true);
    unset($row->response_payload);
    $row->audio_bytes = $payload['audio_bytes'] ?? null;
    $row->usage_status = $payload['usage_status'] ?? null;
    echo json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
