<?php
// Explicit, small live-provider smoke test. No Laravel bootstrap or database access.
require __DIR__.'/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();
$client = new GuzzleHttp\Client(['timeout' => 90]);
$samples = [
    'en' => 'Hello. I am Lena, your logistics assistant.',
    'de' => 'Hallo. Ich bin Lena, Ihre Assistentin für Logistik.',
    'bs' => 'Dobar dan. Ja sam Lena. Kako vam mogu pomoći s prijevozom tereta?',
    'hr' => 'Dobar dan. Ja sam Lena. Mogu vam pomoći s prijevozom tereta.',
    'sr' => 'Добар дан. Ја сам Лена. Могу вам помоћи са превозом терета.',
];
$dir = __DIR__.'/../storage/app/private/speech-smoke';
if (! is_dir($dir)) mkdir($dir, 0700, true);
foreach ($samples as $lang => $text) {
    $response = $client->post('https://openrouter.ai/api/v1/audio/speech', [
        'http_errors' => false,
        'headers' => ['Authorization' => 'Bearer '.($_ENV['OPENROUTER_API_KEY'] ?? '')],
        'json' => ['model' => 'google/gemini-3.1-flash-tts-preview', 'voice' => 'Kore', 'input' => $text, 'response_format' => 'pcm'],
    ]);
    $bytes = (string) $response->getBody();
    echo $lang.' HTTP '.$response->getStatusCode().' '.$response->getHeaderLine('Content-Type').' bytes='.strlen($bytes).PHP_EOL;
    if ($response->getStatusCode() !== 200 || ! str_starts_with($response->getHeaderLine('Content-Type'), 'audio/')) {
        // Only print the provider's error description; never dump headers or request credentials.
        echo (json_decode($bytes, true)['error']['message'] ?? 'Invalid audio response').PHP_EOL;
        exit(1);
    }
    file_put_contents($dir.'/'.$lang.'.wav', App\Services\LenaSpeech::wav($bytes));
}
