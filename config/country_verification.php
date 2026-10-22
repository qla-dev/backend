<?php

// Product defaults, not a declaration of legal requirements. Never turn unreviewed research into a mandatory upload.
return [
    'version' => '2026-10-21-draft',
    'countries' => [
        'BA' => ['name' => 'Bosna i Hercegovina', 'language' => 'bs', 'currency' => 'BAM', 'timezone' => 'Europe/Sarajevo'],
        'HR' => ['name' => 'Hrvatska', 'language' => 'hr', 'currency' => 'EUR', 'timezone' => 'Europe/Zagreb'],
        'RS' => ['name' => 'Srbija', 'language' => 'sr', 'currency' => 'RSD', 'timezone' => 'Europe/Belgrade'],
    ],
    'capabilities' => ['customer', 'carrier', 'forwarder', 'warehouse', 'customs_representative', 'overseas_agent', 'driver'],
    'activities' => ['domestic', 'international', 'own_account'],
    'sources' => [
        'BA' => 'https://www.transportnedozvole.gov.ba/faq',
        'HR' => 'https://eur-lex.europa.eu/eli/reg/2009/1072',
        'RS' => 'https://www.mgsi.gov.rs/lat/dokumenti/prevoz-tereta',
    ],
    'legal_review' => 'pending',
    'mandatory_uploads' => [],
];
