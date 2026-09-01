<?php

namespace App\Services;

// Builds LenaAI's human-sounding confirmation + next-question text for guided questionnaire
// pill answers that are resolved deterministically (see LenaGuidedAnswerController), i.e. without
// any OpenRouter call. Kept as its own file so the phrasing (prefixes, per-step labels, sentence
// templates) stays in one place across bs/de/en rather than scattered through the controller.
class LenaGuidedAnswerResponder
{
    private const PREFIXES = [
        'bs' => ['U redu', 'Super', 'Važi', 'Može', 'Jasno'],
        'de' => ['Alles klar', 'Super', 'Gut', 'Notiert', 'Perfekt'],
        'en' => ['Got it', 'Great', 'Sure', 'Noted', 'Perfect'],
    ];

    // Accusative-case (bs) / natural object-form (de/en) labels for every questionnaire step, used
    // identically in both the "spasila sam {label} kao ..." confirmation and the "unesite {label}" /
    // "odaberite {label}" next-question sentence.
    private const STEP_LABELS = [
        'storageTarget' => ['bs' => 'odredište skladištenja', 'de' => 'das Lagerziel', 'en' => 'the storage destination'],
        'warehouse' => ['bs' => 'skladište za prijem', 'de' => 'das Empfangslager', 'en' => 'the receiving warehouse'],
        'title' => ['bs' => 'naslov', 'de' => 'den Titel', 'en' => 'a title'],
        'transportType' => ['bs' => 'tip transporta', 'de' => 'die Transportart', 'en' => 'the transport type'],
        'goodsType' => ['bs' => 'vrstu robe', 'de' => 'die Warenart', 'en' => 'the goods type'],
        'weight' => ['bs' => 'težinu tereta', 'de' => 'das Gewicht', 'en' => 'the weight'],
        'pallets' => ['bs' => 'broj paleta', 'de' => 'die Palettenanzahl', 'en' => 'the pallet count'],
        'bodyType' => ['bs' => 'tip nadogradnje', 'de' => 'den Aufbautyp', 'en' => 'the body type'],
        'dimensions' => ['bs' => 'dimenzije tereta', 'de' => 'die Abmessungen', 'en' => 'the dimensions'],
        'vehicleType' => ['bs' => 'tip vozila', 'de' => 'den Fahrzeugtyp', 'en' => 'the vehicle type'],
        'loadingEquipment' => ['bs' => 'opremu za utovar', 'de' => 'die Verladeausrüstung', 'en' => 'the loading equipment'],
        'characteristics' => ['bs' => 'karakteristike prevoza', 'de' => 'die Transportmerkmale', 'en' => 'the transport characteristics'],
        'specialRequirements' => ['bs' => 'posebne zahtjeve', 'de' => 'die besonderen Anforderungen', 'en' => 'the special requirements'],
        'transportMode' => ['bs' => 'način prevoza', 'de' => 'die Transportart', 'en' => 'the transport mode'],
        'deliveryProof' => ['bs' => 'dokaz o isporuci', 'de' => 'den Zustellnachweis', 'en' => 'the delivery proof'],
        'pickup' => ['bs' => 'lokaciju preuzimanja', 'de' => 'den Abholort', 'en' => 'the pickup location'],
        'pickupDate' => ['bs' => 'datum preuzimanja', 'de' => 'das Abholdatum', 'en' => 'the pickup date'],
        'delivery' => ['bs' => 'lokaciju isporuke', 'de' => 'den Lieferort', 'en' => 'the delivery location'],
        'deliveryDate' => ['bs' => 'datum isporuke', 'de' => 'das Lieferdatum', 'en' => 'the delivery date'],
        'budget' => ['bs' => 'cijenu prevoza', 'de' => 'den Preis', 'en' => 'the price'],
        'priceTerms' => ['bs' => 'uslove cijene', 'de' => 'die Preisbedingungen', 'en' => 'the price terms'],
        'declaredValue' => ['bs' => 'deklarisanu vrijednost', 'de' => 'den deklarierten Wert', 'en' => 'the declared value'],
        'terms' => ['bs' => 'Incoterm i uslove plaćanja', 'de' => 'die Incoterm- und Zahlungsbedingungen', 'en' => 'the Incoterm and payment terms'],
        'temperature' => ['bs' => 'temperaturni raspon', 'de' => 'den Temperaturbereich', 'en' => 'the temperature range'],
        'requirements' => ['bs' => 'dodatne zahtjeve', 'de' => 'die zusätzlichen Anforderungen', 'en' => 'the additional requirements'],
        'contact' => ['bs' => 'kontakt podatke', 'de' => 'die Kontaktdaten', 'en' => 'the contact details'],
        'notes' => ['bs' => 'napomenu', 'de' => 'eine Anmerkung', 'en' => 'any notes'],
    ];

    public function stepLabel(string $step, string $lang): string
    {
        return self::STEP_LABELS[$step][$lang] ?? self::STEP_LABELS[$step]['en'] ?? $step;
    }

    private function prefix(string $lang): string
    {
        $pool = self::PREFIXES[$lang] ?? self::PREFIXES['en'];

        return $pool[array_rand($pool)];
    }

    // $answeredValue is the human display text the user actually clicked (already in their
    // language, e.g. "Zračni"), null when the step was skipped.
    public function respond(string $answeredStep, string $lang, ?string $answeredValue, ?array $nextStep): string
    {
        $confirmation = $this->confirmation($answeredStep, $lang, $answeredValue);
        $next = $nextStep ? $this->askStep($nextStep['key'], $nextStep['hasOptions'], $lang) : $this->allDone($lang);
        $marker = $nextStep ? "[[LENA_STEP:{$nextStep['key']}]]" : '[[LOAD_READY_TO_POST:complete]]';

        return "{$confirmation}\n\n{$next}\n{$marker}";
    }

    private function confirmation(string $step, string $lang, ?string $answeredValue): string
    {
        $prefix = $this->prefix($lang);
        $label = $this->stepLabel($step, $lang);

        if ($answeredValue === null) {
            return match ($lang) {
                'bs' => "{$prefix}, odabrat ćemo kasnije {$label}.",
                'de' => "{$prefix}, {$label} legen wir später fest.",
                default => "{$prefix}, we'll come back to {$label} later.",
            };
        }

        // Only transportType and priceTerms show a translated, grammatically lower-case adjective
        // as their pill label (e.g. "Zračni", "Fiksna cijena") - lower-casing it here matches how
        // it reads mid-sentence. Every other step's value is either an English catalog term
        // (Curtain, ADR, Cargo Van) or a real name (contact), both of which must keep their casing.
        $value = in_array($step, ['transportType', 'storageTarget', 'priceTerms'], true)
            ? mb_strtolower(mb_substr($answeredValue, 0, 1)).mb_substr($answeredValue, 1)
            : $answeredValue;

        return match ($lang) {
            'bs' => "{$prefix}! Spasila sam {$label} kao {$value}.",
            'de' => "{$prefix}! Ich habe {$label} als {$value} gespeichert.",
            default => "{$prefix}! I saved {$label} as {$value}.",
        };
    }

    // Free-text steps with a specific expected shape (a unit, or a multi-part format like
    // length x width x height) get a concrete format hint and a real example appended to the
    // question, so the user never has to guess how LenaAI wants the value typed.
    private const FORMAT_HINTS = [
        'dimensions' => [
            'bs' => 'u formatu dužina x širina x visina, npr. 2x1.5x1.8',
            'de' => 'im Format Länge x Breite x Höhe, z. B. 2x1.5x1.8',
            'en' => 'as length x width x height, e.g. 2x1.5x1.8',
        ],
        'weight' => [
            'bs' => 'u kilogramima, npr. 1200',
            'de' => 'in Kilogramm, z. B. 1200',
            'en' => 'in kilograms, e.g. 1200',
        ],
        'pallets' => [
            'bs' => 'kao broj, npr. 24',
            'de' => 'als Zahl, z. B. 24',
            'en' => 'as a number, e.g. 24',
        ],
        'budget' => [
            'bs' => 'kao iznos, npr. 850',
            'de' => 'als Betrag, z. B. 850',
            'en' => 'as an amount, e.g. 850',
        ],
        'pickupDate' => [
            'bs' => 'u formatu DD.MM.GGGG, npr. 05.12.2026',
            'de' => 'im Format TT.MM.JJJJ, z. B. 05.12.2026',
            'en' => 'as DD.MM.YYYY, e.g. 05.12.2026',
        ],
        'deliveryDate' => [
            'bs' => 'u formatu DD.MM.GGGG, npr. 07.12.2026',
            'de' => 'im Format TT.MM.JJJJ, z. B. 07.12.2026',
            'en' => 'as DD.MM.YYYY, e.g. 07.12.2026',
        ],
    ];

    private function askStep(string $step, bool $hasOptions, string $lang): string
    {
        $label = $this->stepLabel($step, $lang);

        if ($hasOptions) {
            return match ($lang) {
                'bs' => "Odaberite {$label} od ponuđenih opcija.",
                'de' => "Bitte wählen Sie {$label} aus den angebotenen Optionen.",
                default => "Please choose {$label} from the options offered.",
            };
        }

        $hint = self::FORMAT_HINTS[$step][$lang] ?? self::FORMAT_HINTS[$step]['en'] ?? null;

        return match ($lang) {
            'bs' => $hint ? "Molimo, unesite {$label} {$hint}." : "Molimo, unesite {$label}.",
            'de' => $hint ? "Bitte geben Sie {$label} {$hint} ein." : "Bitte geben Sie {$label} ein.",
            default => $hint ? "Please enter {$label} {$hint}." : "Please enter {$label}.",
        };
    }

    private function allDone(string $lang): string
    {
        return match ($lang) {
            'bs' => 'Svi podaci su prikupljeni, teret je spreman za objavu.',
            'de' => 'Alle Angaben sind vollständig, die Ladung ist bereit zur Veröffentlichung.',
            default => 'Everything is filled in, the load is ready to post.',
        };
    }
}
