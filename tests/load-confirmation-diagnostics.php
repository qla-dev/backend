<?php
// Read-only: inspect the latest conversation containing the reported confirmation.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
echo json_encode(array_intersect_key($db->getConfig(), array_flip(['driver', 'host', 'port', 'database']))).PHP_EOL;
$message = $db->table('messages')->where('body', 'like', '%započeti kreiranje novog tereta%')->orderByDesc('id')->first(['id', 'conversation_id']);
if (! $message) { echo 'Reported phrase not found.'.PHP_EOL; exit; }
foreach ($db->table('messages')->where('conversation_id', $message->conversation_id)->orderByDesc('id')->limit(6)->get(['id', 'sender_user_id', 'body', 'sent_at']) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
