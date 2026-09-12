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
        'ije' => 'e', 'Ije' => 'E', 'IJE' => 'E',
        'mjesec' => 'mesec', 'Mjesec' => 'Mesec',
        'sedmic' => 'nedelj', 'Sedmic' => 'Nedelj',
        'provjer' => 'prover', 'Provjer' => 'Prover',
        'vrijed' => 'vred', 'Vrijed' => 'Vred',
        'izvješt' => 'izvešt', 'Izvješt' => 'Izvešt',
        'rješen' => 'rešen', 'Rješen' => 'Rešen',
        'rješav' => 'rešav', 'Rješav' => 'Rešav',
        'dijel' => 'del', 'Dijel' => 'Del',
        'hiljad' => 'hiljad', 'Hiljad' => 'Hiljad',
        'tačnost' => 'tačnost', 'Tačnost' => 'Tačnost',
        'tačno' => 'tačno', 'Tačno' => 'Tačno',
        'sačuv' => 'sačuv', 'Sačuv' => 'Sačuv',
        'odaber' => 'izaber', 'Odaber' => 'Izaber',
        'odabr' => 'izabr', 'Odabr' => 'Izabr',
        'prevoz' => 'prevoz', 'Prevoz' => 'Prevoz',
        'sedmicu' => 'nedelju', 'Sedmicu' => 'Nedelju',
        'sutra' => 'sutra', 'tjedan' => 'nedelju', 'Tjedan' => 'Nedelju',
    ]);
};

return $translate($base);
