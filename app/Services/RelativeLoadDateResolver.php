<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

class RelativeLoadDateResolver
{
    public function apply(string $description, array $result, array $current = [], ?DateTimeImmutable $serverToday = null): array
    {
        $offset = $this->dayOffset($description);
        if ($offset === null) {
            return $result;
        }

        $today = $serverToday ?? new DateTimeImmutable(
            'today',
            new DateTimeZone((string) config('app.timezone', 'UTC')),
        );
        $target = $this->targetField($description, $result, $current);
        if ($target === null) {
            return $result;
        }

        $other = $target === 'pickupDate' ? 'deliveryDate' : 'pickupDate';
        if (filled($current[$other] ?? null)) {
            $result[$other] = $current[$other];
        }
        $result[$target] = $today->modify("+{$offset} days")->format('Y-m-d');

        return $result;
    }

    private function dayOffset(string $description): ?int
    {
        $normalized = Str::lower(Str::ascii($description));

        if (preg_match('/\b(?:za\s+(\d{1,4})\s+dan(?:a)?|in\s+(\d{1,4})\s+days?|in\s+(\d{1,4})\s+tagen?)\b/i', $normalized, $match) === 1) {
            return (int) collect(array_slice($match, 1))->first(fn ($value) => $value !== '');
        }
        if (preg_match('/\b(?:prekosutra|day\s+after\s+tomorrow|ubermorgen)\b/i', $normalized) === 1) {
            return 2;
        }
        if (preg_match('/\b(?:sutra|tomorrow|morgen)\b/i', $normalized) === 1) {
            return 1;
        }
        if (preg_match('/\b(?:danas|today|heute)\b/i', $normalized) === 1) {
            return 0;
        }

        return null;
    }

    private function targetField(string $description, array $result, array $current): ?string
    {
        $normalized = Str::lower(Str::ascii($description));
        if (preg_match('/\b(?:isporu\w*|dostav\w*|delivery|deliver\w*|liefer\w*|entlad\w*)\b/i', $normalized) === 1) {
            return 'deliveryDate';
        }
        if (preg_match('/\b(?:preuz\w*|utovar\w*|pickup|pick\s+up|abhol\w*|belad\w*)\b/i', $normalized) === 1) {
            return 'pickupDate';
        }
        if (blank($current['pickupDate'] ?? null)) {
            return 'pickupDate';
        }
        if (blank($current['deliveryDate'] ?? null)) {
            return 'deliveryDate';
        }

        $pickupChanged = ($result['pickupDate'] ?? '') !== ($current['pickupDate'] ?? '');
        $deliveryChanged = ($result['deliveryDate'] ?? '') !== ($current['deliveryDate'] ?? '');

        return match (true) {
            $pickupChanged && ! $deliveryChanged => 'pickupDate',
            $deliveryChanged && ! $pickupChanged => 'deliveryDate',
            default => null,
        };
    }
}
