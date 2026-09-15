<?php

namespace App\Support;

final class SerbianCyrillic
{
    /** Convert a localized string or nested catalog to Serbian Cyrillic. */
    public static function convert(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $value[$key] = self::convert($entry);
            }
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $tokens = [];
        $plain = preg_replace_callback('/\[\[.*?\]\]|:[a-z_]+|freightbook(?:\.ai)?|фре(?:и|ј)г?х?тбоок(?:\.(?:аи|ај|ai))?/iu', static function (array $match) use (&$tokens): string {
            $token = "\u{E000}".count($tokens)."\u{E001}";
            $tokens[$token] = preg_match('/^(?:freightbook|фре)/iu', $match[0]) ? 'Freightbook.ai' : $match[0];
            return $token;
        }, $value);  

        $converted = strtr($plain ?? $value, [
            'DŽ' => 'Џ', 'Dž' => 'Џ', 'dž' => 'џ',
            'LJ' => 'Љ', 'Lj' => 'Љ', 'lj' => 'љ',
            'NJ' => 'Њ', 'Nj' => 'Њ', 'nj' => 'њ',
            'Đ' => 'Ђ', 'đ' => 'ђ', 'Č' => 'Ч', 'č' => 'ч',
            'Ć' => 'Ћ', 'ć' => 'ћ', 'Ž' => 'Ж', 'ž' => 'ж',
            'Š' => 'Ш', 'š' => 'ш', 'A' => 'А', 'B' => 'Б', 'C' => 'Ц',
            'D' => 'Д', 'E' => 'Е', 'F' => 'Ф', 'G' => 'Г', 'H' => 'Х',
            'I' => 'И', 'J' => 'Ј', 'K' => 'К', 'L' => 'Л', 'M' => 'М',
            'N' => 'Н', 'O' => 'О', 'P' => 'П', 'R' => 'Р', 'S' => 'С',
            'T' => 'Т', 'U' => 'У', 'V' => 'В', 'W' => 'В', 'X' => 'Кс',
            'Y' => 'Ј', 'Z' => 'З', 'a' => 'а', 'b' => 'б', 'c' => 'ц',
            'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г', 'h' => 'х',
            'i' => 'и', 'j' => 'ј', 'k' => 'к', 'l' => 'л', 'm' => 'м',
            'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р', 's' => 'с',
            't' => 'т', 'u' => 'у', 'v' => 'в', 'w' => 'в', 'x' => 'кс',
            'y' => 'ј', 'z' => 'з',
        ]);

        return strtr($converted, $tokens);
    }
}
