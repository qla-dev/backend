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
$verbose = in_array('--verbose', $argv, true);
$rawInput = preg_replace('/^\xEF\xBB\xBF/', '', stream_get_contents(STDIN)) ?? '';
$rows = json_decode($rawInput, true, flags: JSON_THROW_ON_ERROR);

$normalize = static function (?string $value): string {
    $value = Str::upper(Str::ascii(trim((string) $value)));

    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '') ?? '');
};

// Mappings verified by hand against the live customers table.
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

// Consignee labels absent from the map are resolved against the live customers
// table, but only on an exact normalized name match. Anything less certain is
// reported for a human decision instead of being guessed at; no customer rows
// are ever created here.
$directory = Customer::query()
    ->select(['id', 'name', 'company_name'])
    ->get()
    ->map(fn (Customer $customer): array => [
        'id' => $customer->id,
        'label' => trim((string) ($customer->name ?: $customer->company_name)),
    ])
    ->filter(fn (array $entry): bool => $entry['label'] !== '');

$exactIndex = [];
foreach ($directory as $entry) {
    $key = $normalize($entry['label']);
    if ($key === '') {
        continue;
    }
    // Ambiguous names (several customers normalizing alike) are left unresolved.
    $exactIndex[$key] = array_key_exists($key, $exactIndex) ? null : $entry['id'];
}

$suggest = static function (string $needle) use ($directory, $normalize): array {
    $target = $normalize($needle);

    return $directory
        ->map(function (array $entry) use ($target, $normalize): array {
            $candidate = $normalize($entry['label']);
            similar_text($target, $candidate, $similarity);

            return ['id' => $entry['id'], 'name' => $entry['label'], 'score' => round($similarity, 1)];
        })
        ->sortByDesc('score')
        ->take(3)
        ->values()
        ->all();
};

$parseDate = static function (?string $value): ?Carbon {
    $value = trim((string) $value);
    if ($value === '' || in_array($value, ['-', '.'], true)) {
        return null;
    }
    if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\.?$/', $value, $match)) {
        return Carbon::create((int) $match[3], (int) $match[2], (int) $match[1])->startOfDay();
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $match)) {
        return Carbon::create((int) $match[3], (int) $match[1], (int) $match[2])->startOfDay();
    }

    try {
        return Carbon::parse($value)->startOfDay();
    } catch (Throwable) {
        return null;
    }
};

$parseNumber = static function (?string $value): ?float {
    if (! preg_match('/\d[\d.,]*/', str_replace(' ', '', (string) $value), $match)) {
        return null;
    }
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

// The workbook's KGS and CBM columns are cross-contaminated: the master sheet
// carries values like "1X40HQ" under KGS and "400 KG" under CBM. Read the unit
// written next to the number rather than trusting the column heading.
$looksLikeContainer = static fn (string $text): bool => preg_match('/\d\s*[X*]\s*\d{2}|\b\d{2}\s*(HQ|HC|GP|DV|RF|OT|FR)\b/i', $text) === 1;

$measure = static function (?string $value) use ($parseNumber, $looksLikeContainer): array {
    $text = trim((string) $value);
    if ($text === '' || in_array($text, ['-', '.'], true) || $looksLikeContainer($text)) {
        return ['kind' => null, 'value' => null];
    }
    $number = $parseNumber($text);
    if ($number === null) {
        return ['kind' => null, 'value' => null];
    }
    if (preg_match('/\b(CBM|M3)\b/i', $text)) {
        return ['kind' => 'volume', 'value' => $number];
    }
    if (preg_match('/\b(KGS?|KILO)\b/i', $text)) {
        return ['kind' => 'weight', 'value' => $number];
    }
    if (preg_match('/\bPAL|PLA\b/i', $text)) {
        return ['kind' => 'pallets', 'value' => $number];
    }

    return ['kind' => 'bare', 'value' => $number];
};

$countryCode = static function (string $place): string {
    $place = Str::upper(Str::ascii($place));

    return match (true) {
        preg_match('/SARAJEVO|SJJ|TUZLA|\bTZ\b|LAKTASI|LUKAVAC|BANJA LUKA|PETROVO|KLJUC|BIJELJINA|MOSTAR|ZENICA/', $place) === 1 => 'BA',
        preg_match('/ISTANBUL|TAKEDERI|MERSIN|IZMIR/', $place) === 1 => 'TR',
        preg_match('/ATHENS|PIRAEUS/', $place) === 1 => 'GR',
        preg_match('/PEK|CHINA|SHANG|NINGBO|QINGDAO|XINGANG|SHEKOU|YANTIAN|CN[A-Z]{3}/', $place) === 1 => 'CN',
        preg_match('/HRRJK|RIJEKA|PLOCE|ZAGREB/', $place) === 1 => 'HR',
        preg_match('/PODGORICA|BAR\b/', $place) === 1 => 'ME',
        preg_match('/NEW YORK|USA|\bUS[A-Z]{3}\b/', $place) === 1 => 'US',
        preg_match('/POLAND|MODLNICZKA|GDANSK/', $place) === 1 => 'PL',
        preg_match('/SIKOP|KOPER|SLOVENIA/', $place) === 1 => 'SI',
        preg_match('/BELGRADE|BEOGRAD|NOVI SAD/', $place) === 1 => 'RS',
        preg_match('/HAMBURG|BREMERHAVEN|MUNICH|MUNCHEN/', $place) === 1 => 'DE',
        preg_match('/TRIESTE|ITALY|MILANO/', $place) === 1 => 'IT',
        trim($place) === 'DE' => 'DE',
        trim($place) === 'BG' => 'RS',
        default => 'XX',
    };
};

$isPlace = static fn (?string $value): bool => ! in_array(trim((string) $value), ['', '-', '.'], true);
$text = static fn (?string $value): ?string => ($trimmed = trim((string) $value)) !== '' && ! in_array($trimmed, ['-', '.'], true) ? $trimmed : null;

$created = 0;
$updated = 0;
$skipped = [];
$resolvedByName = [];
$imported = [];

DB::transaction(function () use (
    $rows, $apply, $normalize, $customerIds, $exactIndex, $suggest, $parseDate, $parseNumber,
    $measure, $countryCode, $isPlace, $text,
    &$created, &$updated, &$skipped, &$resolvedByName, &$imported
): void {
    foreach ($rows as $row) {
        $label = trim((string) ($row['consignee'] ?? ''));
        $key = $normalize($label);
        $customerId = $customerIds[$key] ?? null;

        if ($customerId === null && ($exactIndex[$key] ?? null) !== null) {
            $customerId = $exactIndex[$key];
            $resolvedByName[$label] = $customerId;
        }

        if ($customerId === null) {
            $skipped[] = [
                'booking' => $row['booking'] ?? null,
                'consignee' => $label,
                'sheet' => $row['sheet'] ?? null,
                'source_row' => $row['source_row'] ?? null,
                'suggestions' => $suggest($label),
            ];
            continue;
        }

        $date = $parseDate($row['date'] ?? null);
        if ($date === null) {
            continue;
        }

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

        $department = Str::upper(trim((string) ($row['department'] ?? '')));
        $freightMode = Str::upper(trim((string) ($row['freight_mode'] ?? '')));
        $isCustoms = $sheet === 'customs' || $department === 'CUSTOMS' || $freightMode === 'CUSTOMS';

        // The master sheet holds every mode, so fall back to its own columns.
        $transportType = match ($sheet) {
            'air' => 'air',
            'rail' => 'rail',
            'sea_fcl', 'sea_lcl' => 'sea',
            'road', 'customs' => 'road',
            default => match (true) {
                $freightMode === 'AIR' => 'air',
                $freightMode === 'RAIL' => 'rail',
                in_array($freightMode, ['FCL', 'LCL', 'SEA'], true) => 'sea',
                default => 'road',
            },
        };
        $isSea = $transportType === 'sea';

        $priceText = trim((string) ($row['price'] ?? ''));
        preg_match('/\b(EUR|USD|BAM|KM)\b/i', $priceText, $currencyMatch);
        $currency = strtoupper($currencyMatch[1] ?? 'EUR');
        if ($currency === 'KM') {
            $currency = 'BAM';
        }

        // KGS and CBM may each hold a weight, a volume, a pallet count or a
        // container type; place each reading by its own unit.
        $kgs = $measure($row['kgs'] ?? null);
        $cbm = $measure($row['cbm'] ?? null);
        $quantityText = trim((string) ($row['quantity'] ?? ''));
        $quantity = $measure($quantityText);

        $weight = null;
        $volume = null;
        $pallets = null;
        foreach ([$kgs, $cbm, $quantity] as $index => $reading) {
            $kind = $reading['kind'];
            if ($kind === null) {
                continue;
            }
            if ($kind === 'bare') {
                // An unlabelled number means whatever its column is headed.
                $kind = match ($index) { 0 => 'weight', 1 => 'volume', default => 'pallets' };
            }
            match ($kind) {
                'weight' => $weight ??= $reading['value'],
                'volume' => $volume ??= $reading['value'],
                'pallets' => $pallets ??= (int) $reading['value'],
                default => null,
            };
        }

        $etd = $parseDate($row['etd'] ?? null);
        $eta = $parseDate($row['eta'] ?? null);
        $atd = $parseDate($row['atd'] ?? null);
        $transitDays = $etd && $eta && $eta->greaterThanOrEqualTo($etd) ? $etd->diffInDays($eta) : null;

        $insuranceText = trim((string) ($row['insurance'] ?? ''));
        $insured = (bool) preg_match('/^(YES|DA|Y)/i', $insuranceText)
            || (bool) preg_match('/\bOSIGURAN|INSURANCE|\d\s*,\s*\d\s*%/i', $priceText);

        $containerTypes = $text($row['container_types'] ?? null);
        $containerNumber = $text($row['container'] ?? null);
        $teu = $text($row['teu'] ?? null);
        $containerSelections = array_values(array_filter([
            $containerTypes ? ['type' => $containerTypes, 'teu' => $teu, 'number' => $containerNumber] : null,
        ]));

        $statusHistory = [[
            'status' => $status,
            'changed_at' => ($atd ?? $date)->toIso8601String(),
            'source' => 'transport-workbook',
        ]];

        $attributes = [
            'customer_user_id' => 4,
            'consignee_customer_id' => $customerId,
            'company_id' => 1,
            'title' => $booking.' · '.$label,
            'booking_reference' => $booking,
            'insurance' => $insuranceText ?: null,
            'department' => $text($row['department'] ?? null),
            'freight_mode' => $text($row['freight_mode'] ?? null),
            'subdepartment' => $text($row['subdepartment'] ?? null),
            'status' => $status,
            'status_change' => json_encode($statusHistory, JSON_UNESCAPED_UNICODE),
            'transport_type' => $transportType,
            'transport_mode' => $text($row['freight_mode'] ?? null),
            'cargo_type' => trim((string) ($row['freight_mode'] ?: $row['department'] ?: Str::upper($sheet))),
            'goods_type' => 'General',
            'weight_kg' => max(0.01, $weight ?? 0.01),
            'volume_m3' => $volume,
            'pallets' => $pallets,
            'quantity_measure' => $quantityText ?: null,
            'teu' => $teu,
            'container_types' => $containerTypes,
            'container_number' => $containerNumber,
            'container_selections' => $containerSelections === [] ? null : json_encode($containerSelections, JSON_UNESCAPED_UNICODE),
            'bl_type' => $isSea && $containerNumber ? 'original' : null,
            'etd_at' => $etd,
            'atd_at' => $atd,
            'transit_days' => $transitDays,
            'shipper_name' => $text($row['shipper'] ?? null),
            'mediator' => $text($row['mediator'] ?? null),
            'incoterms' => $text($row['incoterms'] ?? null),
            'price_insurance' => $priceText ?: null,
            'profit_loss' => $text($row['profit_loss'] ?? null),
            'budget' => $parseNumber($priceText),
            'declared_value' => $parseNumber($priceText),
            'shipment_value_currency' => $currency,
            'currency' => $currency,
            'payment_terms' => 'negotiable',
            'is_negotiable' => false,
            'insurance_required' => $insured,
            'customs_required' => $isCustoms,
            'cmr_required' => in_array($transportType, ['road', 'rail'], true),
            'is_fragile' => false,
            'requires_adr' => false,
            'requires_tail_lift' => false,
            'must_be_trackable' => true,
            'is_urgent' => false,
            'notes' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'internal_comments' => $marker,
            'published_at' => $date,
            'completed_at' => $status === 'finished' ? ($atd ?? $date) : null,
        ];

        if (! $apply) {
            $imported[] = [
                'booking' => $booking, 'sheet' => $sheet, 'customer_id' => $customerId,
                'consignee' => $label, 'transport_type' => $transportType, 'status' => $status,
                'weight_kg' => $attributes['weight_kg'], 'volume_m3' => $volume, 'marker' => $marker,
            ];
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
            $load->stops()->createMany([
                ['type' => 'pickup', 'position' => 1, 'city' => $origin, 'country_code' => $countryCode($origin), 'window_starts_at' => $etd ?? $date],
                ['type' => 'delivery', 'position' => 2, 'city' => $destination, 'country_code' => $countryCode($destination), 'window_starts_at' => $eta],
            ]);
        }

        $imported[] = [
            'id' => $load->id, 'booking' => $booking, 'sheet' => $sheet,
            'customer_id' => $customerId, 'consignee' => $label,
        ];
    }
});

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'input_rows' => count($rows),
    'importable_rows' => count($imported),
    'created' => $created,
    'updated' => $updated,
    'resolved_by_exact_name' => $resolvedByName,
    'skipped_rows' => count($skipped),
    'skipped_by_consignee' => collect($skipped)->groupBy('consignee')->map->count()->sortKeys()->all(),
];

if ($verbose) {
    $summary['skipped'] = $skipped;
    $summary['imported'] = $imported;
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
