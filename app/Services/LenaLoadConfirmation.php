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
        // Read the post-load skill's routing resource before selecting the mode.
        $rules = json_decode(file_get_contents(__DIR__.'/../../agents/lena/post-load/skills/guided-voice-confirmations.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([$pair.'_yes' => $rules[$pair.'_yes'] ?? [], $pair.'_no' => $rules[$pair.'_no'] ?? [], 'yes' => $rules['yes'], 'no' => $rules['no']] as $action => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('~^(?:'.$pattern.')$~u', $text)) {
                    return in_array($action, ['yes', 'no'], true) ? $pair.'_'.$action : $action;
                }
            }
        }
        return null;
    }
}
