<?php

$base = require __DIR__.'/../bs/lena.php';

$translate = static function (mixed $value) use (&$translate): mixed {
    if (is_array($value)) {
        foreach ($value as $key => $entry) {
            $value[$key] = $translate($entry);
        }
        return $value;
    }

    if (! is_string($value)) {
        return $value;
    }

    return strtr($value, [
        'Provjer' => 'Provjer', 'provjer' => 'provjer', 'provjera' => 'provjera',
        'sedmic' => 'tjedn', 'Sedmic' => 'Tjedn',
        'tačnost' => 'točnost', 'Tačnost' => 'Točnost',
        'tačno' => 'točno', 'Tačno' => 'Točno',
        'vrijed' => 'vrijed', 'izvješt' => 'izvješt', 'rješ' => 'rješ',
        'hiljad' => 'tisuć', 'Hiljad' => 'Tisuć',
        'sačuv' => 'sprem', 'Sačuv' => 'Sprem',
        'zabilješk' => 'bilješk', 'Zabilješk' => 'Bilješk',
        'pretraž' => 'pretraž', 'Pretraž' => 'Pretraž',
        'odaber' => 'odaber', 'Odaber' => 'Odaber',
        'odabr' => 'odabr', 'Odabr' => 'Odabr',
        'kompanij' => 'tvrtk', 'Kompanij' => 'Tvrtk',
        'račun' => 'račun', 'Račun' => 'Račun',
        'pošiljk' => 'pošiljk', 'teret' => 'teret',
        'prevoz' => 'prijevoz', 'Prevoz' => 'Prijevoz',
        'vozač' => 'vozač', 'skladišt' => 'skladišt',
        'kontejner' => 'kontejner', 'dokument' => 'dokument',
        'datotek' => 'datotek', 'otkaži' => 'otkaži',
        'unesite' => 'unesite', 'Unesite' => 'Unesite',
    ]);
};

return $translate($base);
