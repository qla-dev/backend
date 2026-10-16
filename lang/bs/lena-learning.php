<?php

return [
    'title' => 'Learn with Lena',
    'unavailable' => 'Obuka još nije uključena na ovom serveru.',
    'hint_button' => 'Pomozi mi',
    'progress' => 'Samostalno potvrđene kompetencije: :count/3',
    'phases' => ['assessment' => 'Početna provjera', 'lesson' => 'Lekcija', 'final' => 'Završna provjera'],
    'feedback' => [
        'resume' => 'Dobro došli! Ovaj pilot obuhvata podatke o teretu, dokumente i praćenje. Počinjemo s tri pitanja, zatim zajedno vježbamo. Napredak se čuva u ovom razgovoru. Vježbe ne stvaraju stvarne poslovne zapise.',
        'choose' => 'Odaberite jedan od ponuđenih odgovora. Ovaj pilot ocjenjuje te izbore; ne ocjenjuje slobodan tekst ili privitke.',
        'hint' => 'Evo pomoći. Odgovor uz pomoć je vježba; završna potvrda kompetencije zahtijeva samostalan odgovor.',
        'incorrect' => 'Taj odgovor ne rješava zadatak. Pregledajte lekciju ili zatražite pomoć pa pokušajte ponovo. Ovaj pokušaj se bilježi kao vježba uz pomoć.',
        'assessment_incorrect' => 'Ovu temu ćemo uvježbati kroz lekcije. Nastavite na sljedeći zadatak.',
        'correct' => 'Tačno. Nastavite na sljedeći zadatak.',
        'repeat' => 'Za neke završne odgovore bila je potrebna pomoć. Ponovite završnu provjeru samostalno da potvrdite sve tri kompetencije.',
    ],
    'completed' => 'Završili ste uvodni pilot i samostalno odgovorili na sva tri završna pitanja. Potvrđeno: podaci za zahtjev za prijevoz, provjera dokumenata i identifikacija pošiljke za praćenje. Ovo je interni rezultat obuke, a ne stručni certifikat. Sljedeće: vježbajte u aplikaciji s mentorom.',
    /* Archived fixed questions; AI training now generates exercises.
    'lessons' => [
        'route' => [
            'teaching' => 'Upotrebljiv zahtjev za prijevoz sadrži lokacije preuzimanja i isporuke, termine, robu i količinu. Nedostajuće podatke provjerite s odgovornom osobom prije objave; ne izmišljajte adresu ili rok.',
            'question' => 'Klijent šalje težinu robe, ali nema lokacije ni datuma preuzimanja. Šta uraditi prije objave?',
            'hint' => 'Vozač mora znati gdje i kada preuzeti robu. Potvrdite nedostajuće podatke.',
            'choices' => ['a' => 'Izmisliti adresu i datum preuzimanja', 'b' => 'Tražiti od klijenta potvrdu lokacije i termina', 'c' => 'Objaviti kao kompletno samo s težinom'],
        ],
        'documents' => [
            'teaching' => 'Izdvajanje podataka iz dokumenata olakšava unos. Provjerite podatke prema originalu i pošiljci, posebno količine, jedinice i reference. Neslaganja riješite prije korištenja podataka.',
            'question' => 'Količina iz skeniranog dokumenta razlikuje se od originala. Koji je sljedeći korak?',
            'hint' => 'Automatsko izdvajanje može pogriješiti. Provjerite original i riješite neslaganje.',
            'choices' => ['a' => 'Provjeriti original i ispraviti ili razjasniti neslaganje', 'b' => 'Uvijek vjerovati izdvojenom podatku', 'c' => 'Obrisati dokument bez provjere'],
        ],
        'tracking' => [
            'teaching' => 'Praćenje počinje identifikacijom tačne pošiljke. Koristite njen tačan broj za praćenje ili podržanu referencu. Provjerite posljednji dostupan status i vrijeme; stari događaj ne garantuje trenutnu lokaciju.',
            'question' => 'Više pošiljki ima isto odredište. Kako provjeriti status prave pošiljke?',
            'hint' => 'Samo odredište ne identifikuje pošiljku jednoznačno.',
            'choices' => ['a' => 'Izabrati prvu pošiljku za to odredište', 'b' => 'Pretpostaviti da je najstariji događaj trenutna lokacija', 'c' => 'Koristiti tačnu referencu i provjeriti posljednji status i vrijeme'],
        ],
    ],
    */
];
