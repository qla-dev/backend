<?php

namespace App\Services;

use Illuminate\Support\Str;

class LenaLoadConfirmation
{
    public static function action(?string $answer, ?string $question): ?string
    {
        // Resolve the pending choice identically for typed and transcribed text.
        if (! preg_match('/\[\[LENA_OPTIONS:([^\]]+)\]\]/', (string) $question, $match)) return null;
        $options = array_map('trim', explode(',', $match[1]));
        $pair = null;
        foreach (['start_add', 'upload', 'continue_add'] as $prefix) {
            if (in_array($prefix.'_yes', $options, true) && in_array($prefix.'_no', $options, true)) {
                $pair = $prefix;
                break;
            }
        }
        if (! $pair) return null;
        // Generic transliteration renders Serbian ј as "j" or "y" depending on
        // the library. Normalize Serbian letters explicitly before ASCII folding.
        $normalized = strtr(Str::lower((string) $answer), [
            'а'=>'a', 'б'=>'b', 'в'=>'v', 'г'=>'g', 'д'=>'d', 'ђ'=>'dj', 'е'=>'e', 'ж'=>'z',
            'з'=>'z', 'и'=>'i', 'ј'=>'j', 'к'=>'k', 'л'=>'l', 'љ'=>'lj', 'м'=>'m', 'н'=>'n',
            'њ'=>'nj', 'о'=>'o', 'п'=>'p', 'р'=>'r', 'с'=>'s', 'т'=>'t', 'ћ'=>'c', 'у'=>'u',
            'ф'=>'f', 'х'=>'h', 'ц'=>'c', 'ч'=>'c', 'џ'=>'dz', 'ш'=>'s',
        ]);
        $text = trim(preg_replace('/[\p{P}\p{Z}\s]+/u', ' ', Str::lower(Str::ascii($normalized))));
        // Match complete affirmative phrases, not merely a leading "yes":
        // "yes, but not now" must never become permission to start.
        if (preg_match('/^(?:yes(?: please| i do| i want to| let s start| lets start| go ahead| of course)?|sure(?: go ahead)?|go ahead|let s (?:start|do it)|da(?: zelim| hocu| molim| naravno| hajde| ajde| moze| kreni| pocnimo| nastavi){0,3}|zelim|hocu|(?:hajde|ajde)(?: da pocnemo| pocnimo| kreni| moze)?|moze(?: hajde| kreni)?|naravno|u redu(?: kreni| hajde)?|ja(?: bitte| gerne| ich mochte| ich will| machen wir| los geht s)?|gerne|los geht s)$/u', $text)) {
            return $pair.'_yes';
        }
        if (preg_match('/^(?:no(?: thanks| thank you| not now)?|ne(?: zelim|cu| hvala)?|necu|nein(?: danke)?|not now|ne sada)$/u', $text)) {
            return $pair.'_no';
        }
        return null;
    }
}
