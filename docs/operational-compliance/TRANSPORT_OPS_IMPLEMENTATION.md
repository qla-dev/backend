> **Važeća dopuna 22.10.2026 — OPS je isključivo operativa ROAD / AIR / SEA / WH / CUSTOMS.** Finansijski editori, kataloške cijene, marža i povezivanje cijelih faktura uklonjeni su iz OPS UI/API-ja. Finansijske radnje ostaju u Računovodstvu; OPS samo čita status naplate. Integracijska administracija konektora ostaje u profilu firme na zasebnim rutama. Jedan nalog/upit podržava više operativnih usluga (SEA Nansha–Rijeka + ROAD Rijeka–Sarajevo). Stare ručne veze prema cijeloj fakturi više ne potvrđuju naplatu. Izmijenjen dokaz daje efektivni status review_required i kontrolisano ponovno otvaranje uz razlog/reviziju/audit. Ciljana provjera: 156 test izvršavanja, 1.020 assertions, TypeScript i build prolaze. Daljnja raspodjela po stavci/iznosu radi se isključivo u Računovodstvu kroz task fb-ops-module-links-20261022. Slijedi korisnički lokalni pregled; nema main/deploya.
>
> Tekst ispod je historija prethodne implementacije; ova dopuna ima prednost nad ranijim opisima finansijskih radnji, ručnih veza cijele fakture i uslova unosa stvarnog troška u OPS-u.

# FreightBook — Transportni nalozi – OPS

22.10.2026. Lokalna implementacija završnog toka; čeka korisnički pregled prije spajanja i objave.

## Pregled

Lokalni pregled: http://127.0.0.1:5149/sandbox-preview.html
Radna kopija: C:/Users/DT User/Documents/Codex/2026-10-21/n/work/FreightBook

Pregled koristi stvarne React komponente, kontrolere, autorizaciju kompanije i poslovne servise. Prijava je simulirana lokalnim testnim korisnikom; podaci su demonstracijski. Backend za svaki zahtjev koristi SQLite :memory:, uz lokalni JSON snimak samo tih testnih podataka. Nema veze s produkcijskom bazom. Lokalni pregled nije puni E2E stvarne prijave i produkcijske infrastrukture.

## Implementirano

- Transportni nalozi – OPS: naziv u navigaciji i prevodima, Nalozi, Operativni pregled, OPS TODAY i Operativne postavke. ID-evi i postojeći API ugovori ostaju kompatibilni.
- Odvojeno prikazani transport, naplata i završetak naloga. Isporučen/neplaćen i unaprijed plaćen/neisporučen posao ne može biti završen.
- Završetak koristi sve povezane važeće izlazne fakture, knjiženja i alocirane uplate iz postojećeg računovodstva. Djelimična naplata, neizdana/neknjižena faktura i nepotpuno fakturisana ugovorena usluga blokiraju završetak. Različita valuta traži računovodstveni pregled; nije dodana druga knjiga uplata.
- Više faktura po poslu je podržano. Ista faktura ne može se ponovo izdati. Stornirane fakture izuzimaju se tek kada postoji knjiženi korektivni događaj; usluga bez važeće fakture ne postaje automatski plaćena.
- Poništenje alokacije je knjiženi ispravak s razlogom i datumom, uz postojeće accounting payments/post ovlasti. Finansijska obrada završenog naloga ponovo se otvara uz audit, bez brisanja isporuke. Ponovljen zahtjev ne duplira ispravak.
- Zatvaranje i knjiženja koriste zajednički kompanijski lock i transakcije. PANTHEON-ov završetak prolazi istu lokalnu provjeru, uključujući operativne uslove i troškove.
- Početni zadaci i dokumentacija za cestovni, pomorski, avionski, željeznički, multimodalni i skladišni posao, uz FTL/LTL/FCL/LCL varijante. Carina i T1 uključuju se samo kada su primjenjivi.
- Dionice imaju vrstu transporta, polazište/odredište, status, odgovornu osobu, rok i dokumente. Nepotpuna dionica sprečava završetak. Dokazi se provjeravaju SHA-256 otiskom; promijenjeni dokazi vraćaju provjeru na pregled.
- Operativa koristi postojeću shipment checklistu. OPS TODAY i Operativni pregled prikazuju iste stavke. Dodani su filteri transporta, faze, osobe, roka, statusa, naplate i nedostajućih dokumenata.
- Operativni predlošci su verzionisani po vrsti transporta/varijanti; aktivni nalozi ostaju na svojoj verziji. Izmjene toka imaju provjeru revizije i historiju.
- BIMS i fitosanitarni predmeti zadržani su kao uslovna ručna evidencija. Tranzit vodi LRN/MRN, dionicu, dokumente, status i narednu radnju. Otvorene tranzitne obaveze ostaju vidljive u OPS TODAY i nakon komercijalnog završetka; zasebno ih je moguće ažurirati.
- Profil firme zadržava državu, poslovne kapacitete i verifikaciju. Operativni predlošci su izdvojeni u OPS postavke.
- Produkcijski frontend build više ne sadrži jučerašnji hardkodirani localhost API. VITE_API_URL može eksplicitno odrediti endpoint; razvojni i produkcijski izbori su odvojeni. Demo adapter i lokalni router nalaze se odvojeno od produkcijske isporuke.

CRM/upit/ponuda, booking, shipment i računovodstvo koriste postojeće veze; nije kreiran paralelan CRM ili TMS. Ponovljeno kreiranje iz iste pošiljke vraća postojeći nalog.

## Provjere

Ciljani paket: 121 test, 740 provjera. Obuhvat: checklist regresije, kompanijske ovlasti, finansijski uslov završetka, više faktura, djelimične uplate, poništenje uplate, idempotentnost, domaći nalog, multimodalne dionice, sistemske stavke, revizije, verzije predložaka, integritet dokumenata, tranzit nakon komercijalnog završetka i regresije računovodstva/PANTHEON-a. Nije pokrenut kompletan test paket cijelog FreightBooka.

TypeScript --noEmit i Vite produkcijski build provjereni su zasebno. Postojeća upozorenja o CSS font importu i velikim bundleovima ostaju.

Browser provjera kroz stvarni lokalni API: 400/1200 prikazuje saldo 800 i blokiran završetak; doplata omogućava završetak; knjiženo poništenje doplate vraća finansijsku obradu. Provjereni su spremanje naredne radnje, objava predloška, filter avionskog transporta i uski prikaz bez prelijevanja cijele stranice.

Tačne rezultate i logove daje priloženi validation.json. Testovi su provedeni isključivo na eksplicitnom SQLite :memory: spoju.

## Pregled i naredni korak

U demonstracijskom pregledu otvori DEMO-OPS-001. U rubrici „Test naplate na demonstracijskom nalogu” može se knjižiti testna doplata; nakon toga otvoriti nalog i završiti ga, pa poništiti testnu doplatu i provjeriti povratak u finansijsku obradu.

Originalni C:/xampp/htdocs/FreightBook, javni freightbook.ai, njegove baze, backend i frontend nisu objavljivani ili mijenjani ovim radom. Radna kopija nije spojena na main.

Paket sadrži promijenjene izvore i odvojene backend/frontend patcheve s manifestom osnovnih i novih hashova. Lokalni harness i simulirana prijava su u review-only/, izvan produkcijskih patcheva. Prije spajanja potrebno je uskladiti promjene s aktuelnim udaljenim backend/frontend granama; XAMPP osnova može biti starija. Potom slijede pregled migracija i stvarni staging tok s prijavom i uploadom. Objavu na main i redeploy radimo poslije korisničkog pregleda.

Nove migracije: 2026_10_21_000001_create_operational_compliance (jučerašnja osnova) i 2026_10_22_000001_create_transport_flows. Ne izvršavati seed/reset poslovne baze. Produkcijske migracije nisu pokretane.

Vanjske BIMS/UIO integracije, institucionalna potvrda i konačna pravna matrica država ostaju buduće faze, kako je dogovoreno u specifikaciji. UIO adapter ne šalje ništa institucijama; deklarant.ai ostaje mjesto obrade deklaracija.
