<?php
// Small explicit live-provider accounting probe. No application/database bootstrap.
require __DIR__.'/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();
$client = new GuzzleHttp\Client(['timeout' => 90, 'headers' => ['Authorization' => 'Bearer '.($_ENV['OPENROUTER_API_KEY'] ?? '')]]);
if (isset($argv[1])) {
    $id = $argv[1];
} else {
$response = $client->post('https://openrouter.ai/api/v1/audio/speech', ['json' => [
    'model' => 'google/gemini-3.1-flash-tts-preview', 'voice' => 'Kore', 'input' => 'Dobar dan.', 'response_format' => 'pcm',
]]);
$id = $response->getHeaderLine('X-Generation-Id');
echo 'Audio HTTP '.$response->getStatusCode().' bytes='.strlen((string) $response->getBody()).' generation='.$id.PHP_EOL;
foreach ($response->getHeaders() as $name => $value) {
    if (preg_match('/usage|cost|token|generation|provider/i', $name)) echo $name.': '.implode(', ', $value).PHP_EOL;
}
}
for ($i = 0; $i < 3; $i++) {
    $usage = $client->get('https://openrouter.ai/api/v1/generation', ['query' => ['id' => $id], 'http_errors' => false]);
    $data = json_decode((string) $usage->getBody(), true)['data'] ?? [];
    echo json_encode(['status' => $usage->getStatusCode(), 'data' => array_intersect_key($data, array_flip(['id', 'model', 'total_cost', 'tokens_prompt', 'tokens_completion', 'native_tokens_prompt', 'native_tokens_completion', 'provider_name']))]).PHP_EOL;
    if (isset($data['total_cost'])) exit(0);
    usleep(500000);
}
exit(1);
