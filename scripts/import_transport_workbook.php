<?php

use App\Models\Customer;
use App\Models\Load;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$rawInput = preg_replace('/^\xEF\xBB\xBF/', '', stream_get_contents(STDIN)) ?? '';
$rows = json_decode($rawInput, true, flags: JSON_THROW_ON_ERROR);

$normalize = static function (?string $value): string {
    $value = Str::upper(Str::ascii(trim((string) $value)));
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '') ?? '');
};

// Only mappings verified against the live customers table are included here.
$customerIds = [
    'ALCOOP' => 36140,
    'ALFA SPED' => 5249,
    'ALUSAR' => 24628,
    'AMANI' => 15798,
    'AVIOR' => 35471,
    'BEPRO' => 27289,
    'CENTROTAX CILEK' => 1888,
    'COMFORT' => 3533,
    'COMFORT FACTORY' => 3533,
    'COPAL' => 15727,
    'DMV DOO' => 11903,
    'DREAM SAT' => 6265,
    'ESSOPHARM' => 7270,
    'EURO VVD' => 32939,
    'FARED CO O S P D MAKSUZ' => 244,
    'FORESTER' => 622,
    'INGFOREST' => 1463,
    'INVEL DOO' => 14538,
    'IRFAX LOG' => 2705,
    'KALEA' => 935,
    'KISS' => 8858,
    'KOMEL ELECTRONICS' => 14590,
    'LMV DOO' => 16172,
    'LOKO' => 18329,
    'LUKS' => 32563,
    'LUKS DOO' => 32563,
    'MDD TRADE' => 10842,
    'MEDIC MARKET' => 33666,
    'MIHAJLOVIC DOO' => 25912,
    'MOBILAND' => 29202,
    'MOBILAND BILJANA' => 29202,
    'NEXEN DOO' => 30416,
    'OPTINOVA' => 14773,
    'OPTOVISION' => 25682,
    'PLAROLA' => 17102,
    'PROFMEDIA' => 12263,
    'R S' => 14471,
    'RIS' => 20087,
    'SAPLAST' => 569,
    'SIDRA DOO' => 28287,
    'SMARVET' => 22514,
    'TARGET' => 16872,
    'TEHNOPLAST' => 12457,
    'VITACARE' => 36677,
    'VRHPOLJE PROMET' => 10427,
    'WILLONA' => 2771,
    'WINGS MEDIA' => 14395,
    'ZADA PHARM' => 6171,
    'ZIDNI PANELI' => 33393,
    'ZIDNI PANELI DOO' => 33393,
    'ZIDNI PANELI DOO BIH' => 33393,
];

$missingCustomerIds = collect($customerIds)->values()->unique()->reject(
    fn (int $id): bool => Customer::query()->whereKey($id)->exists()
)->values()->all();

if ($missingCustomerIds !== []) {
    fwrite(STDERR, 'Mapped customer IDs no longer exist: '.implode(', ', $missingCustomerIds).PHP_EOL);
    exit(2);
}

$parseDate = static function (?string $value): ?Carbon {
    $value = trim((string) $value);
    if ($value === '' || in_array($value, ['-', '.'], true)) return null;
    if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\.?$/', $value, $match)) {
        return Carbon::create((int) $match[3], (int) $match[2], (int) $match[1])->startOfDay();
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $match)) {
        return Carbon::create((int) $match[3], (int) $match[1], (int) $match[2])->startOfDay();
    }
    try { return Carbon::parse($value)->startOfDay(); } catch (Throwable) { return null; }
};

$parseNumber = static function (?string $value): ?float {
    if (! preg_match('/\d[\d.,]*/', str_replace(' ', '', (string) $value), $match)) return null;
    $number = $match[0];
    if (str_contains($number, ',') && str_contains($number, '.')) {
        $number = strrpos($number, ',') > strrpos($number, '.')
            ? str_replace(['.', ','], ['', '.'], $number)
            : str_replace(',', '', $number);
    } else {
        $number = str_replace(',', '.', $number);
    }
    return is_numeric($number) ? (float) $number : null;
};

$countryCode = static function (string $place): string {
    $place = Str::upper(Str::ascii($place));
    return match (true) {
        preg_match('/SARAJEVO|SJJ|TUZLA|\bTZ\b|LAKTASI|LUKAVAC|BANJA LUKA|PETROVO|KLJUC/', $place) === 1 => 'BA',
        preg_match('/ISTANBUL|TAKEDERI/', $place) === 1 => 'TR',
        preg_match('/ATHENS/', $place) === 1 => 'GR',
        preg_match('/PEK|CHINA|SHANG|NINGBO|QINGDAO|XINGANG|CN[A-Z]{3}/', $place) === 1 => 'CN',
        preg_match('/HRRJK|RIJEKA/', $place) === 1 => 'HR',
        preg_match('/PODGORICA/', $place) === 1 => 'ME',
        preg_match('/NEW YORK/', $place) === 1 => 'US',
        preg_match('/POLAND|MODLNICZKA/', $place) === 1 => 'PL',
        trim($place) === 'DE' => 'DE',
        trim($place) === 'BG' => 'RS',
        default => 'XX',
    };
};

$isPlace = static fn (?string $value): bool => ! in_array(trim((string) $value), ['', '-', '.'], true);
$created = 0;
$updated = 0;
$skipped = [];
$imported = [];

DB::transaction(function () use (
    $rows, $apply, $normalize, $customerIds, $parseDate, $parseNumber, $countryCode, $isPlace,
    &$created, &$updated, &$skipped, &$imported
): void {
    foreach ($rows as $row) {
        $label = trim((string) ($row['consignee'] ?? ''));
        $customerId = $customerIds[$normalize($label)] ?? null;
        if ($customerId === null) {
            $skipped[] = ['booking' => $row['booking'] ?? null, 'consignee' => $label, 'sheet' => $row['sheet'] ?? null];
            continue;
        }

        $date = $parseDate($row['date'] ?? null);
        if ($date?->year !== 2026) continue;

        $sheet = (string) ($row['sheet'] ?? 'unknown');
        $booking = trim((string) ($row['booking'] ?? ''));
        $sourceRow = (int) ($row['source_row'] ?? 0);
        $marker = sprintf('[transport-workbook:%s:%s:row%d]', $sheet, $booking, $sourceRow);
        $rawStatus = Str::lower(trim((string) ($row['status'] ?? 'open')));
        $status = match ($rawStatus) {
            'finished', 'closed' => 'finished',
            'pending' => 'pending',
            'cancelled', 'canceled' => 'cancelled',
            default => 'opened',
        };
        $transportType = match ($sheet) {
            'air' => 'air',
            'sea_fcl', 'sea_lcl' => 'sea',
            default => 'road',
        };
        $priceText = trim((string) ($row['price'] ?? ''));
        preg_match('/\b(EUR|USD|BAM|KM)\b/i', $priceText, $currencyMatch);
        $currency = strtoupper($currencyMatch[1] ?? 'EUR');
        if ($currency === 'KM') $currency = 'BAM';
        $weight = max(0.01, $parseNumber($row['kgs'] ?? null) ?? 0.01);
        $volume = $parseNumber($row['cbm'] ?? null);
        $quantityText = trim((string) ($row['quantity'] ?? ''));
        $pallets = preg_match('/PAL/i', $quantityText) ? (int) ($parseNumber($quantityText) ?? 0) : null;
        $completedAt = $status === 'finished' ? ($parseDate($row['atd'] ?? null) ?? $date) : null;
        $attributes = [
            'customer_user_id' => 4,
            'consignee_customer_id' => $customerId,
            'company_id' => 1,
            'title' => $booking.' · '.$label,
            'status' => $status,
            'transport_type' => $transportType,
            'cargo_type' => trim((string) ($row['freight_mode'] ?: $row['department'] ?: Str::upper($sheet))),
            'goods_type' => 'General',
            'weight_kg' => $weight,
            'volume_m3' => $volume,
            'pallets' => $pallets,
            'budget' => $parseNumber($priceText),
            'currency' => $currency,
            'payment_terms' => 'negotiable',
            'is_fragile' => false,
            'requires_adr' => false,
            'requires_tail_lift' => false,
            'must_be_trackable' => true,
            'is_urgent' => false,
            'notes' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'internal_comments' => $marker,
            'published_at' => $date,
            'completed_at' => $completedAt,
        ];

        if (! $apply) {
            $imported[] = ['booking' => $booking, 'customer_id' => $customerId, 'consignee' => $label, 'marker' => $marker];
            continue;
        }

        $load = Load::query()->where('internal_comments', $marker)->first();
        if ($load) {
            $load->update($attributes);
            $load->stops()->delete();
            $updated++;
        } else {
            $load = Load::query()->create(['public_id' => (string) Str::uuid(), ...$attributes]);
            $created++;
        }

        $origin = trim((string) ($row['departure'] ?? ''));
        $destination = trim((string) ($row['arrival'] ?? ''));
        if ($isPlace($origin) && $isPlace($destination)) {
            $eta = $parseDate($row['eta'] ?? null);
            $load->stops()->createMany([
                ['type' => 'pickup', 'position' => 1, 'city' => $origin, 'country_code' => $countryCode($origin), 'window_starts_at' => $date],
                ['type' => 'delivery', 'position' => 2, 'city' => $destination, 'country_code' => $countryCode($destination), 'window_starts_at' => $eta],
            ]);
        }
        $imported[] = ['id' => $load->id, 'booking' => $booking, 'customer_id' => $customerId, 'consignee' => $label];
    }
});

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'input_rows' => count($rows),
    'importable_rows' => count($imported),
    'created' => $created,
    'updated' => $updated,
    'skipped_rows' => count($skipped),
    'skipped_by_consignee' => collect($skipped)->groupBy('consignee')->map->count()->sortKeys()->all(),
    'imported' => $imported,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
