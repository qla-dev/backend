<?php

namespace App\Services;

/** The curated customs-law library available to Lena's legal-consultation mode. */
class LegalSourceCatalog
{
    public const DIRECTORY = 'legal-sources';

    public function sources(): array
    {
        return [
            ['id' => 'customs-tariff-law', 'file' => 'B-3-Zakon-o-carinskoj-tarifi-5812.pdf', 'title' => 'Zakon o carinskoj tarifi'],
            ['id' => 'customs-policy-amendment-2026', 'file' => 'B-H-S-1-7-O-o-izmj-i-dop-Odluke-o-provvodenju-Zakona-o-carinskoj-politici-u-BiH-Sl-list-28-26-21-04-2026.pdf', 'title' => 'Izmjene Odluke o provođenju Zakona o carinskoj politici u BiH'],
            ['id' => 'customs-declaration-instructions', 'file' => 'B-1-U-o-popunjavanju-car-prijave-i-deklaracije-za-priv-smjestajslist-9-23-10-02-23.pdf', 'title' => 'Uputstvo o popunjavanju carinske prijave i deklaracije za privremeni smještaj'],
            ['id' => 'jci-fields', 'file' => 'B-1-1-Prilog-1-Polja-za-popunjavanje-JCI.pdf', 'title' => 'Prilog 1: Polja za popunjavanje JCI'],
            ['id' => 'jci-import', 'file' => 'B-1-3-Prilog-3-Uvoz-Uputstva-o-JCI.pdf', 'title' => 'Prilog 3: Uvoz, uputstva o JCI'],
            ['id' => 'customs-value', 'file' => 'B-1-Uputstvo-o-utvrdivanju-carinske-vrijednosti-Sluzbeni-glasnik-BiH-broj-7407.pdf', 'title' => 'Uputstvo o utvrđivanju carinske vrijednosti'],
            ['id' => 'customs-debt-security', 'file' => 'B-H-S-1-Uputstvo-o-osiguranju-carinskog-duga-s-list-30-23-28-04-23.pdf', 'title' => 'Uputstvo o osiguranju carinskog duga'],
            ['id' => 'customs-warehouse', 'file' => 'B-H-S-5N-U-o-carinskom-skladistu-i-postupku-carinskog-skladistenja-s-list-46-22-15-07.pdf', 'title' => 'Uputstvo o carinskom skladištu i postupku carinskog skladištenja'],
            ['id' => 'inward-processing', 'file' => 'B-6-U-o-postupku-unutrasnje-obrade-i-prilozi-s-list-53-22-09-08-22.pdf', 'title' => 'Uputstvo o postupku unutrašnje obrade'],
            ['id' => 'home-import-clearance', 'file' => 'B-17-U-o-kucnom-uvoznom-carinjenju-s-list-57-22-23-08-22.pdf', 'title' => 'Uputstvo o kućnom uvoznom carinjenju'],
            ['id' => 'temporary-import', 'file' => 'B-9-Uputstvo-o-privremenom-uvozu-Sluzbeni-glasnik-BiH-broj-6112.pdf', 'title' => 'Uputstvo o privremenom uvozu'],
            ['id' => 'efta-agreement', 'file' => '6-1-Ugovor-EFTA-18-02-2015.pdf', 'title' => 'Ugovor EFTA'],
            ['id' => 'cefta-origin', 'file' => 'B-2-Uputstvo-o-provedbi-pravila-o-porijeklu-u-preferencijalnoj-trgovini-u-okviru-srednjeevropskog-sporazuma-o-slobodnoj-trgovini-Sluzbeni-glasnik-B.pdf', 'title' => 'Uputstvo o pravilima porijekla u CEFTA trgovini'],
            ['id' => 'cefta-joint-committee', 'file' => '9-Odluka-o-zajednickog-odbora-centralnoevropskog-sporazuma-o-slobodn-trgovin-med-sluzb-list-9-22-26-12-22.pdf', 'title' => 'Odluka Zajedničkog odbora CEFTA'],
        ];
    }

    public function find(string $id): ?array
    {
        foreach ($this->sources() as $source) {
            if ($source['id'] === $id) return $source;
        }
        return null;
    }

    public function promptCatalog(): string
    {
        return collect($this->sources())->map(fn (array $source) => "{$source['id']}: {$source['title']}")->implode("\n");
    }
}
