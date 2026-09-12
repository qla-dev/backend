<?php

namespace App\Services;

class LenaIntent
{
    public static function detect(?string $text): ?string
    {
        $text = mb_strtolower((string) $text);
        if (preg_match('/\b(?:carin\p{L}*|pristojb\p{L}*|deklaracij\p{L}*|ocp\p{L}*|pdv|jci|customs|duties|tariff|vat|zoll\p{L}*|einfuhr\p{L}*|mehrwertsteuer)\b/u', $text)) {
            return 'legal';
        }
        if (preg_match('/\b(?:izračun\p{L}*|izracun\p{L}*|obračun\p{L}*|obracun\p{L}*|kalkul\p{L}*|calcul\p{L}*|berechn\p{L}*|rechn\p{L}*)\b/u', $text)) {
            return 'free';
        }

        return null;
    }

    public static function isPaymentRequest(?string $text): bool
    {
        return preg_match('/\b(?:uplat\p{L}*|plaćan\p{L}*|placan\p{L}*|payment\p{L}*|pay|zahl\p{L}*|überweis\p{L}*)\b/iu', (string) $text) === 1;
    }
}
