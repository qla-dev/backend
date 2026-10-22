# BIMS: lokalni tok
Ops ovlaštenik kompanije kreira lokalni predmet. Vozač bez ops ovlasti ne može mijenjati inspekcijsku evidenciju. Službenu odluku izdaje nadležni organ; FreightBook samo evidentira dokaz i korisnika koji ga je unio.

Lokalni statusi su aplikacijski nacrt: draft, submitted, received, supplement_requested, laboratory, approved, partial, rejected, archived. Njihovi nazivi ne predstavljaju potvrđene BIMS wire kodove. Ne nameće se izmišljena vanjska tranzicijska mašina. Za svaki status osim nacrta traže se službena referenca, nadležni organ i dokument naloga. Svaka izmjena čuva audit i povećava reviziju. Djelimična odluka traži ishode po stavkama.

Scenariji:
1. SEA FCL uvoz: nalog → upload dokumenta → lokalni zdravstveni predmet → ručno evidentirana odluka s dokazom. Transportni/carinski status se ne mijenja.
2. Fitosanitarna dopuna: zaprimljen predmet → dopuna s rokom i odgovornom osobom → nova odluka; stara verzija ostaje u auditu.
3. Više kontrola: dva predmeta istog naloga imaju nezavisne reference i rokove. Jedna odluka ne završava drugi predmet.
4. Djelimičan ishod: navesti stavke i ishode; partial se ne tretira kao potpuno puštanje. Zatvaranje naloga s označenom obaveznom inspekcijom traži odobrene predmete.
5. Tuđa kompanija, zastarjela revizija ili dokument drugog naloga: zahtjev se odbija bez izmjene podataka.

Vrsta robe sama ne uključuje automatski sve kontrole. Primjenjivost određuje ovlašten čovjek; automatizovana pravila zahtijevaju potvrđen regulatorni opseg.
