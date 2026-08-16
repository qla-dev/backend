<?php

use App\Models\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rawInput = preg_replace('/^\xEF\xBB\xBF/', '', stream_get_contents(STDIN)) ?? '';
$input = json_decode($rawInput, true, flags: JSON_THROW_ON_ERROR);

$normalize = static function (?string $value): string {
    $value = Str::upper(Str::ascii(trim((string) $value)));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';

    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
};

$customers = Customer::query()
    ->select(['id', 'name', 'company_name', 'tax_number', 'city', 'source', 'source_id'])
    ->get()
    ->map(function (Customer $customer) use ($normalize): array {
        $display = trim((string) ($customer->name ?: $customer->company_name));

        return [
            'id' => $customer->id,
            'name' => $display,
            'normalized' => $normalize($display),
            'tax_number' => $customer->tax_number,
            'city' => $customer->city,
            'source' => $customer->source,
            'source_id' => $customer->source_id,
        ];
    })
    ->filter(fn (array $customer): bool => $customer['normalized'] !== '')
    ->values();

$results = collect($input)->map(function (string $label) use ($customers, $normalize): array {
    $needle = $normalize($label);
    $needleTokens = array_values(array_filter(explode(' ', $needle), fn (string $token): bool => strlen($token) > 1));

    $matches = $customers->map(function (array $customer) use ($needle, $needleTokens): array {
        $candidate = $customer['normalized'];
        similar_text($needle, $candidate, $similarity);
        $candidateTokens = array_values(array_filter(explode(' ', $candidate), fn (string $token): bool => strlen($token) > 1));
        $intersection = array_intersect($needleTokens, $candidateTokens);
        $union = array_unique(array_merge($needleTokens, $candidateTokens));
        $tokenScore = $union === [] ? 0 : count($intersection) / count($union) * 100;
        $exact = $candidate === $needle;
        $contains = strlen($needle) >= 4 && str_contains($candidate, $needle);
        $reverseContains = strlen($candidate) >= 4 && str_contains($needle, $candidate);
        $score = $exact ? 100 : ($contains ? 94 : ($reverseContains ? 91 : max($similarity, $tokenScore)));

        return [...$customer, 'score' => round($score, 2), 'exact' => $exact];
    })->sortByDesc('score')->take(5)->values()->all();

    return ['label' => $label, 'normalized' => $needle, 'matches' => $matches];
})->values()->all();

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
