<?php

namespace App\Services;

use App\Models\User;

class LenaGuest
{
    public const TOKEN = 'lena-guest';
    public const CBM_SKILL = 'legal/skills/calculate-packing-list-cbm.md';

    public static function active(?User $user): bool
    {
        return $user?->currentAccessToken()?->name === self::TOKEN;
    }

    public static function cbm(?User $user): bool
    {
        return self::active($user) && $user->tokenCan('demo:cbm');
    }

    public static function instructions(?User $user, bool $includeCbm = true): string
    {
        if (! self::active($user)) return '';
        $prompt = "\nThis is a guest LIVE CALL demo. Listen carefully, retain the caller's confirmed facts and corrections, and ask one missing item at a time. Never invent shipment data. The conversation is saved for the platform administrator. When the caller asks to post a load, use the existing post-load workflow and carry their supplied cargo facts and calculated CBM into the draft. Publication requires the caller to review and confirm with the publish button in the call's draft panel. Never claim publication before the server confirms it. ";
        if ($includeCbm && self::cbm($user)) {
            $prompt .= "The caller selected a guided CBM demonstration. Start it proactively. Until they switch tasks, use this existing skill, including for short numeric answers. Do not offer legal consultations.\n";
            $prompt .= app(LenaSkillSelector::class)->instructions([self::CBM_SKILL]);
        }
        return $prompt;
    }

    public static function greeting(string $lang, bool $cbm): string
    {
        return match ($lang) {
            'de' => $cbm ? 'Hallo, ich bin Lena. Wir berechnen gemeinsam Ihr Ladevolumen. Wie viele Pakete haben Sie?' : 'Hallo, ich bin Lena. Was möchten Sie besprechen?',
            'bs', 'hr' => $cbm ? 'Zdravo, ja sam Lena. Hajdemo zajedno izračunati CBM vašeg tereta. Koliko paketa imate?' : 'Zdravo, ja sam Lena. O čemu želite razgovarati?',
            'sr' => $cbm ? 'Zdravo, ja sam Lena. Hajdemo zajedno izračunati CBM vašeg tereta. Koliko paketa imate?' : 'Zdravo, ja sam Lena. O čemu želite da razgovaramo?',
            default => $cbm ? 'Hi, I’m Lena. Let’s calculate your cargo volume together. How many packages do you have?' : 'Hi, I’m Lena. What would you like to talk about?',
        };
    }
}
