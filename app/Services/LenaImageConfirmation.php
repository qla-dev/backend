<?php

namespace App\Services;

class LenaImageConfirmation
{
    public static function required(bool $training, ?string $action, string $text, array $skills): bool
    {
        if (! $training || $action !== null) {
            return false;
        }
        if (preg_match('/\b(?:nemoj|ne\s+generi\p{L}*|do\s+not|don[’\x27]t|nicht|немој|не\s+генер\p{L}*)\b/iu', $text) === 1) {
            return false;
        }

        return in_array('training/skills/generate-image.md', $skills, true)
            || preg_match('/\b(?:generi\p{L}*|nacrt\p{L}*|napravi|generate|draw|create|erstell\p{L}*|zeichn\p{L}*|генер\p{L}*|нацрт\p{L}*)\b.{0,60}\b(?:slik\p{L}*|mockup|image|picture|bild\p{L}*|слик\p{L}*)\b/iu', $text) === 1;
    }

    public static function reply(string $lang): string
    {
        $text = match ($lang) {
            'bs', 'hr' => 'Napravit ću sliku prema opisu i posljednjim izmjenama u ovoj konverzaciji, koristeći priložene slike kao referencu. Želite li pokrenuti generisanje?',
            'sr' => 'Направићу слику према опису и последњим изменама у овој конверзацији, користећи приложене слике као референцу. Желите ли покренути генерисање?',
            'de' => 'Ich werde ein Bild anhand der Beschreibung und der letzten Änderungen in dieser Unterhaltung erstellen und angehängte Bilder als Referenz verwenden. Möchten Sie die Generierung starten?',
            default => 'I will create an image based on the description and latest changes in this conversation, using attached images as references. Would you like to start generation?',
        };

        return $text."\n[[LENA_OPTIONS:training_image_yes,training_image_no]]";
    }
}
