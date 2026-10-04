<?php

namespace App\Services\Accounting;

use Illuminate\Validation\ValidationException;

/** Decimal strings throughout: never calculate money with binary floating point. */
final class Decimal
{
    public static function value(mixed $value, int $scale = 2): string
    {
        $value = (string) $value;
        if (! preg_match('/^-?\d{1,12}(?:\.\d{1,8})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Invalid decimal amount.']);
        }

        return self::round($value, $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, bccomp($value, '0', 10) < 0 ? '-'.$half : $half, $scale);
    }

    public static function sum(iterable $values): string
    {
        $sum = '0.00';
        foreach ($values as $value) {
            $sum = bcadd($sum, (string) $value, 2);
        }

        return $sum;
    }

    public static function convert(string $amount, string $rate): string
    {
        return self::round(bcmul($amount, $rate, 10));
    }
}
