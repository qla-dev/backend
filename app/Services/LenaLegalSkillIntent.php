<?php

namespace App\Services;

use Illuminate\Support\Str;

class LenaLegalSkillIntent
{
    /** User messages only, newest first. Carry a task through answers, not unrelated questions. */
    public static function resolve(array $messages): ?string
    {
        foreach ($messages as $body) {
            $text = Str::lower(Str::ascii((string) $body));
            if (str_contains($text, '[[lena_action:')) return null;
            // A comparison can itself mention CBM: prefer its more specific intent.
            if (preg_match('/\b(?:lcl|fcl)\b/', $text)) {
                return 'legal/skills/compare-lcl-fcl.md';
            }
            if (preg_match('/\b(?:cbm|kubik\w*|kubikaz\w*|kubikmeter)\b|m[³3]/u', $text)
                || preg_match('/(?:izrac|obrac|calcul|berechn)\w*.*(?:packing|paking|packlist|pakirn|dimenz|volum)/u', $text)) {
                return 'legal/skills/calculate-packing-list-cbm.md';
            }
            if (LenaIntent::isPaymentRequest($body)) return null;
            // Follow-ups may contain only dimensions, counts, corrections, or an upload response.
            $continuation = preg_match('/^\s*\d|\b(?:ponovo|ponovno|isprav\w*|gresk\w*|pitaj|jedno po jedno|nemam|priloz\w*|attached|paket\w*|karton\w*|kutij\w*|dimenz\w*|duzin\w*|sirin\w*|visin\w*|cm|mm|kg|again|recalcul\w*|correct\w*|one at a time|dimensions?|cartons?|packages?|nochmal|erneut|korrekt\w*|abmess\w*|kartons?)\b/u', $text);
            if (! $continuation) return null;
        }

        return null;
    }
}
