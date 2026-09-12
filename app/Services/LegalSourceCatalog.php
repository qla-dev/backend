<?php

namespace App\Services;

/** The curated customs-law library available to Lena's legal-consultation mode, split by jurisdiction. */
class LegalSourceCatalog
{
    public const DIRECTORY = 'legal-sources';

    public const JURISDICTIONS = [
        'BA' => 'Bosnia and Herzegovina',
        'EU' => 'European Union',
        'HR' => 'Croatia',
        'RS' => 'Serbia',
    ];

    /**
     * BiH sources live in storage/app/legal-sources. EU and Croatian sources are version-controlled
     * next to their instructions in agents/lena/{folder}/documents.
     */
    public function sources(): array
    {
        return [
            ['id' => 'customs-tariff-law', 'jurisdiction' => 'BA', 'file' => 'B-3-Zakon-o-carinskoj-tarifi-5812.pdf', 'title' => 'Zakon o carinskoj tarifi'],
            ['id' => 'customs-policy-amendment-2026', 'jurisdiction' => 'BA', 'file' => 'B-H-S-1-7-O-o-izmj-i-dop-Odluke-o-provvodenju-Zakona-o-carinskoj-politici-u-BiH-Sl-list-28-26-21-04-2026.pdf', 'title' => 'Izmjene Odluke o provođenju Zakona o carinskoj politici u BiH'],
            ['id' => 'customs-declaration-instructions', 'jurisdiction' => 'BA', 'file' => 'B-1-U-o-popunjavanju-car-prijave-i-deklaracije-za-priv-smjestajslist-9-23-10-02-23.pdf', 'title' => 'Uputstvo o popunjavanju carinske prijave i deklaracije za privremeni smještaj'],
            ['id' => 'jci-fields', 'jurisdiction' => 'BA', 'file' => 'B-1-1-Prilog-1-Polja-za-popunjavanje-JCI.pdf', 'title' => 'Prilog 1: Polja za popunjavanje JCI'],
            ['id' => 'jci-import', 'jurisdiction' => 'BA', 'file' => 'B-1-3-Prilog-3-Uvoz-Uputstva-o-JCI.pdf', 'title' => 'Prilog 3: Uvoz, uputstva o JCI'],
            ['id' => 'customs-value', 'jurisdiction' => 'BA', 'file' => 'B-1-Uputstvo-o-utvrdivanju-carinske-vrijednosti-Sluzbeni-glasnik-BiH-broj-7407.pdf', 'title' => 'Uputstvo o utvrđivanju carinske vrijednosti'],
            ['id' => 'customs-debt-security', 'jurisdiction' => 'BA', 'file' => 'B-H-S-1-Uputstvo-o-osiguranju-carinskog-duga-s-list-30-23-28-04-23.pdf', 'title' => 'Uputstvo o osiguranju carinskog duga'],
            ['id' => 'customs-warehouse', 'jurisdiction' => 'BA', 'file' => 'B-H-S-5N-U-o-carinskom-skladistu-i-postupku-carinskog-skladistenja-s-list-46-22-15-07.pdf', 'title' => 'Uputstvo o carinskom skladištu i postupku carinskog skladištenja'],
            ['id' => 'inward-processing', 'jurisdiction' => 'BA', 'file' => 'B-6-U-o-postupku-unutrasnje-obrade-i-prilozi-s-list-53-22-09-08-22.pdf', 'title' => 'Uputstvo o postupku unutrašnje obrade'],
            ['id' => 'home-import-clearance', 'jurisdiction' => 'BA', 'file' => 'B-17-U-o-kucnom-uvoznom-carinjenju-s-list-57-22-23-08-22.pdf', 'title' => 'Uputstvo o kućnom uvoznom carinjenju'],
            ['id' => 'temporary-import', 'jurisdiction' => 'BA', 'file' => 'B-9-Uputstvo-o-privremenom-uvozu-Sluzbeni-glasnik-BiH-broj-6112.pdf', 'title' => 'Uputstvo o privremenom uvozu'],
            ['id' => 'efta-agreement', 'jurisdiction' => 'BA', 'file' => '6-1-Ugovor-EFTA-18-02-2015.pdf', 'title' => 'Ugovor EFTA'],
            ['id' => 'cefta-origin', 'jurisdiction' => 'BA', 'file' => 'B-2-Uputstvo-o-provedbi-pravila-o-porijeklu-u-preferencijalnoj-trgovini-u-okviru-srednjeevropskog-sporazuma-o-slobodnoj-trgovini-Sluzbeni-glasnik-B.pdf', 'title' => 'Uputstvo o pravilima porijekla u CEFTA trgovini'],
            ['id' => 'cefta-joint-committee', 'jurisdiction' => 'BA', 'file' => '9-Odluka-o-zajednickog-odbora-centralnoevropskog-sporazuma-o-slobodn-trgovin-med-sluzb-list-9-22-26-12-22.pdf', 'title' => 'Odluka Zajedničkog odbora CEFTA'],
            ['id' => 'eu-union-customs-code', 'jurisdiction' => 'EU', 'folder' => 'legal-eu', 'file' => 'union-customs-code-952-2013-20221212.pdf', 'title' => 'Uredba (EU) br. 952/2013, Carinski zakonik Unije'],
            ['id' => 'hr-import-vat-instruction', 'jurisdiction' => 'HR', 'folder' => 'legal-cro', 'file' => 'uputa-8-23-obracunski-pdv.pdf', 'title' => 'Uputa Carinske uprave RH br. 8/23 o obračunskom PDV-u pri uvozu'],
            ['id' => 'hr-import-vat-leaflet', 'jurisdiction' => 'HR', 'folder' => 'legal-cro', 'file' => 'obracunski-pdv-letak.pdf', 'title' => 'Letak Carinske uprave RH: Obračunski PDV pri uvozu'],
            ['id' => 'rs-customs-law', 'jurisdiction' => 'RS', 'folder' => 'legal-srb', 'file' => 'carinski-zakon-95-2018-138-2022.pdf', 'title' => 'Carinski zakon Republike Srbije, Sl. glasnik RS 95/18 do 138/22'],
            ['id' => 'rs-vat-law', 'jurisdiction' => 'RS', 'folder' => 'legal-srb', 'file' => 'zakon-o-pdv-84-2004-109-2025.pdf', 'title' => 'Zakon o porezu na dodatu vrednost Republike Srbije, Sl. glasnik RS 84/04 do 109/25'],
        ];
    }

    public function find(string $id): ?array
    {
        foreach ($this->sources() as $source) {
            if ($source['id'] === $id) return $source;
        }
        return null;
    }

    public function path(array $source): string
    {
        return isset($source['folder'])
            ? base_path('agents'.DIRECTORY_SEPARATOR.'lena'.DIRECTORY_SEPARATOR.$source['folder'].DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.$source['file'])
            : storage_path('app'.DIRECTORY_SEPARATOR.self::DIRECTORY.DIRECTORY_SEPARATOR.$source['file']);
    }

    public function promptCatalog(): string
    {
        return collect($this->sources())
            ->map(fn (array $source) => "{$source['id']} [".self::JURISDICTIONS[$source['jurisdiction']]."]: {$source['title']}")
            ->implode("\n");
    }
}
