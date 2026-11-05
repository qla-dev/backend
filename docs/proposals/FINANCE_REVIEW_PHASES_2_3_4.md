# FreightBook — pregled za Qlu i Kenu prije implementacije faza 2–4

Datum: 5.11.2026. Status: prijedlog za zajednički pregled i dopune. Faze 2–4 nisu odobrene za implementaciju ovim zahtjevom. Faza 1 — prava po firmi i odjeli/pododjeli — odobrena je za rad na mainu.

Osnova analize: frontend `9c1339c65851f6f655efddbebe4c06daa7ac30ef`, backend `cef1ac771c5c0f553fd2b6690e708e3703cc8b05`; remote main grane ponovo provjerene 5.11.2026. Nije izvršen prijavljeni produkcijski test. Navedene promjene su prijedlozi, osim izričito opisanog postojećeg ponašanja.

## Cilj pregleda

Qla i Keno trebaju pregledati obim, provjeriti pretpostavke u kodu i dopuniti poslovna pravila. Na boardu ostaviti primjedbe i odluke po fazama prije novog rada. Postavljanje ovih kartica ne smije pokrenuti agente, deployment, migracije ili promjenu postojećih finansijskih podataka.

Glavna odluka: jedan skup faktura, uplata, knjiženja i fiskalnih operacija, s različitim ekranima prema zadatku i pravima. Transportni nalog je zajednički prostor pošiljke; Finansije obrađuju povezana finansijska dokumenta, a tracking prikazuje dozvoljeni status istog posla.

## Faza 2 — jedinstven modul Finansije

### Problem koji rješavamo

`AccountingView.tsx` u režimu `pos` prikazuje izlazne račune i fiskalne izvještaje; računovodstveni režim prikazuje ulazne račune i knjige. `FinanceView.tsx` paralelno nudi pregled i određene ulaze za kreiranje računa. Time obična faktura za ugovoreni transport djeluje kao POS posao, a korisnik ima više ulaza za istu dokumentaciju.

### Predloženi ekran i odgovornost

| Cjelina | Odgovornost | Postojeća osnova |
|---|---|---|
| Pregled | Potraživanja, obaveze, rokovi, novčani tok i marže | FinanceView i postojeći računovodstveni podaci |
| Izlazni računi | Priprema, odobrenje, izdavanje i korekcije | InvoiceList, InvoiceEditor, InvoiceDetail, AccountingInvoices |
| Ulazni računi i troškovi | Dobavljačke fakture, dokazi i raspodjela troška po poslovima | Incoming invoices i accounting_cost_allocations |
| Naplata i plaćanja | Banka/blagajna, avansi i povezivanje uplata s računima | Postojeći bankarski i payment/allocation servisi |
| Računovodstvo | Konta, knjiženja, periodi, pravila i izvještaji | Postojeći LedgerPanels i backend servisi |
| Blagajna/POS | Brza prodaja i podržani fiskalni postupci | PosTerminal, SmartPosReports i SmartPos |

Sve cjeline koriste aktivnu firmu, postojeći finansijski API i iste identifikatore računa. Sakrivanje ekrana nije autorizacija; server provjerava svaku radnju. Integracije ostaju pod Profil firme → Integracije.

### Primjer prije i poslije

Operater završi pripremu transportnog posla. Osoba s pravom fakturisanja iz naloga bira „Pripremi račun“. Otvara se nacrt izlazne fakture s vezom na isti nalog. Odobravatelj izdaje račun prema svojim ovlastima. Ne mora otvarati POS niti imati pravo fiskalizacije samo da bi pripremio B2B fakturu.

Račun iz POS-a također je isti invoice zapis; dobija dodatne fiskalne podatke kada je taj postupak primjenjiv i konfigurisan. Ne kopira se u drugi sistem faktura.

### Implementacijski koraci poslije pregleda

1. Popisati sadašnje rute, pozive iz naloga i stare direktne linkove; napraviti mapu njihovog budućeg odredišta.
2. Preurediti AccountingView po poslovnim cjelinama. Zadržati postojeće zajedničke komponente za listu, editor i detalj.
3. FinanceView svesti na pregled nad istim evidencijama. Uklanjati duplirane mutacije tek kad zajednički tok pokrije isti slučaj.
4. POS terminal i izvještaje prikazati prema eksplicitnim ovlastima i stvarnoj konfiguraciji, bez uslovljavanja svih izlaznih faktura POS pristupom.
5. Stare invoice zapise prvo klasifikovati: izvor, company, accounting_managed, izdavanje, knjiženje i naplata. Staru oznaku paid ne pretvarati automatski u knjiženu uplatu.
6. Sačuvati postojeći DataTable izgled, prevode i ponašanje na manjim ekranima. Ne uvoditi novu zbirnu računovodstvenu bazu radi preuređenja menija.

### Odluke za Qlu i Kenu

- Potvrditi nazive cjelina i najčešće putanje za logističku firmu, prijevoznika i firmu koja samo kupuje uslugu.
- Utvrditi kojem postojećem toku pripadaju svi legacy računi i šta se prikazuje korisniku dok zapis nije usklađen.
- Potvrditi ko priprema, odobrava i izdaje račun; mala firma može spojiti odgovornosti eksplicitnim pravima.
- Razgraničiti „zaprimljen dokument“ od „ulazna faktura u obradi“. Upload dokumenta sam ne znači odobren trošak ili knjiženje.
- Potvrditi treba li blagajna poseban podmeni ili prečac. Fiskalna primjenjivost ne izvodi se iz samog naziva role.

### Kriteriji prihvata

Izlazni račun može se obraditi bez POS ovlasti. Isti račun ima iste iznose i statuse u nalogu, pregledu, računovodstvu i POS-u. Stari direktni linkovi vode na odgovarajući novi ekran. Ne nastaje duplikat računa; nema gubitka historije ili automatske promjene starih knjiženja.

## Faza 3 — naplata, POS i završetak naloga

### Postojeća osnova i tačka provjere

`AccountingPayments` već ima raspodjelu uplata i njeno poništenje. `TransportSettlement` čita izdavanje, knjiženja, povezane uplate i poništenja te provjerava dostavu, dokumentaciju i pokrivenost fakturisanjem.

U `PosTerminal::charge` vidljiv je slijed kreiranja računa → predaja → odobrenje → izdavanje → fiskalizacija. U toj UI funkciji nema zasebnog poziva za knjiženje i raspodjelu uplate. Potrebno je provjeriti cijeli backend tok; to nije dokaz da u svim drugim putanjama naplata nedostaje.

### Predložena stanja

| Dimenzija | Primjeri stanja | Izvor |
|---|---|---|
| Transport | Planiran, u izvršenju, isporučen | Pošiljka, dionice i dozvoljeni događaji |
| Račun | Nacrt, u odobrenju, izdat, korigovan | Invoice servis |
| Knjiženje | Neknjiženo, knjiženo, poništeno odgovarajućim zapisom | Knjige |
| Naplata | Nefakturisano, neplaćeno, djelimično, plaćeno | Važeće knjižene raspodjele uplata |
| Fiskalizacija | Nije pokrenuta, u obradi, potvrđena, neuspjela, nepoznat ishod | Fiskalni servis i dokaz rezultata |
| Nalog | Otvoren, čeka finansije, spreman za završetak, završen | Postojeća pravila završetka |

Predloženi prikaz: „Transport isporučen · Naplaćeno 400/1.000 · Nalog otvoren“. Fiskalizacija nije dokaz uplate. Odabran način plaćanja karticom nije dokaz potvrđene kartične transakcije.

### Primjeri koje treba pokriti

- Avans 400 prije preuzimanja, ostatak 600 nakon isporuke: avans se evidentira i pravilno veže; pošiljka ne postaje isporučena zbog uplate.
- Jedan račun za dva naloga: jasno odrediti da li nalog čeka punu naplatu računa ili svoju dokazanu raspodjelu. Trenutni servis posmatra saldo povezane fakture. Ne uvoditi tiho novo pravilo.
- Jedan nalog s više računa i korekcijom: računaju se samo važeće obaveze i knjiženja; ne zbrajati prvobitni i korektivni dokument kao dva obična prihoda.
- Uplata poništena poslije zatvaranja: ponovno finansijsko razmatranje naloga i audit, bez gubitka događaja o ranijoj isporuci.
- Račun izdat, fiskalni uređaj ne odgovara: prvo razriješiti prethodni ishod; ponovljeni klik ne smije napraviti novi račun ili novu fiskalnu operaciju.
- Dobavljač nije plaćen, kupac jeste: prikazati obavezu firme. Ne dodavati plaćanje svih dobavljača kao novi univerzalni uslov završetka naloga bez odluke.

### Implementacijski koraci poslije pregleda

1. Mapirati sve načine nastanka uplate i njene raspodjele, uključujući POS, banku, avans i storno.
2. Zajednički proces mora vezati svaku uplatu za firmu, izvor i odgovarajući račun. Računovodstveni dokaz ima prednost nad ručnom oznakom paid.
3. Osigurati ponovljivost bez duplikata kroz cijeli niz radnji. Postojeći fiskalni request_key ne štiti sam po sebi prethodno kreiranje nove fakture pri novom pokušaju.
4. Za prekid nakon izdavanja nastaviti s istog računa; za nepoznat fiskalni ishod ostaviti provjeru dokaza prije ponavljanja.
5. Sačuvati postojeći TransportSettlement i jasno razlikovati preduslove za zatvaranje, spremnost za zatvaranje i stvarno zatvoren nalog.
6. Marže odvojiti na planirane i stvarne; definisati neto/bruto osnov i valute. Knjižena marža nije stanje novca na računu.

### Odluke za Qlu i Kenu

- Pravilo raspodjele djelimične uplate zbirnog računa na naloge.
- Obrada preplate, avansa, korekcije, otpisa i posla nulte vrijednosti; izuzeci ne smiju nastati implicitno.
- Dokaz gotovinske i kartične naplate i prava za storno/povrat.
- Da li se zatvaranje pokreće ručno kada su uslovi ispunjeni ili automatski; do odluke čuvati sadašnje ponašanje.
- Ko smije vidjeti nabavnu cijenu, maržu i bankarske podatke. Vanjski učesnik vidi samo vlastita dozvoljena dokumenta i finansijske podatke.

### Kriteriji prihvata

Puna naplata bez isporuke ne zatvara nalog. Isporuka bez pune naplate ne zatvara nalog. Poništenje relevantne uplate mijenja finansijski rezultat i pokreće postojeću provjeru ponovnog otvaranja. Zbirne fakture imaju izričito pravilo. Dupli klik, timeout i ponovni pokušaj ne stvaraju dvostruki račun, uplatu ili fiskalni račun.

## Faza 4 — provjere, prelazak i prihvat

Ova faza je zajednički okvir prihvata. Njene provjere primjenjuju se na svaku isporuku, uključujući fazu 1; nisu odložene do završetka cjelokupne reorganizacije. Šira finansijska testna matrica prvo ide na pregled.

| Scenarij | Očekivanje |
|---|---|
| Ista osoba u dvije firme, različite role | Promjena role u A ne mijenja prava u B ni globalni account tip |
| Finance bez transportnog pododjela | Dozvoljene finansijske radnje u svojoj firmi, bez obaveznog Road izbora |
| Operater u Road i Air | Jasna raspodjela poslova; pododjel ne dodjeljuje finansijske radnje |
| Opozvano članstvo ili ovlast | API odbija pristup nakon opoziva, uključujući staru otvorenu stranicu |
| Kupac, dobavljač i prevoznik | Nema internih troškova, marži ni tuđih dokumenata |
| Fakturisanje bez POS ovlasti | Priprema i izdavanje prema dozvolama bez fiskalnih akcija |
| Nacrt/izdat/knjižen račun | Ekrani i nalog prikazuju dosljedna, odvojena stanja |
| Avans, djelimična i puna uplata | Iznosi i ostatak odgovaraju knjigama i raspodjelama |
| Korekcija i poništenje uplate | Ispravan saldo, audit i odgovarajući status naloga |
| Zbirna faktura / više faktura za nalog | Odabrana pravila provjerena na konkretnim iznosima |
| Dupli klik i prekid mreže | Ponovni pokušaj nastavlja isti postupak bez duplikata |
| Stari linkovi i legacy računi | Pristup očuvan ili jasno objašnjena potrebna obrada |

### Prelazak i provjera podataka

Napraviti popis članstava i starih odjela bez izmjena. Predloženo mapiranje prvo prikazati u izvještaju: stara vrijednost, nova funkcija/pododjel, stvarne zadržane ovlasti i nejasni slučajevi. Nejasne zapise ne tretirati kao odobrenje dodatnih prava. Aditivne promjene sheme i eventualna popuna moraju imati zaseban plan izvršavanja, provjeru pogođene baze i mogućnost povratka na prethodni kod bez brisanja poslovnih podataka.

Backend testovi rade samo na dokazano izolovanoj SQLite `:memory:` bazi s eksplicitnim postavkama i provjerom efektivne konekcije. Zatim frontend typecheck/build, provjera ugovora API-ja i sintetički pregled uloga. Produkcijski poslovni test evidentirati zasebno i jasno razlikovati od javnog asset/health pregleda.

### Isporuka za pregled

- Diff i revizije frontenda/backenda, popis zahvaćenih API-ja i opis kompatibilnosti.
- Matrica prava prije/poslije i mapiranje odjela, bez objavljivanja privatnih korisničkih podataka.
- Migracijski plan bez resetovanja ili seedanja poslovne baze.
- Rezultati relevantnih testova, screenshotovi uloga i poznata ograničenja.
- Povratak prethodnog koda i način očuvanja zapisa nastalih nakon promjene.
- Kartica „Čeka test“ s konkretnim kriterijima; status konačnog prihvata tek po provjeri.

## Zasebni obim odobrene faze 1

Dozvoljeno sada: prava vezana za firmu, odvajanje poslovne funkcije i pododjela, opcionalan operativni opseg za Finance, ispravni opisi rola, usklađene serverske i UI provjere i testovi tog paketa. Postojeće eksplicitne računovodstvene ovlasti se čuvaju. Promjena globalne role iz timskog ekrana prestaje tek kada su svi zavisni tokovi prebačeni na članstvo firme.

U ovoj fazi ne mijenjati finansijske menije, model fakturisanja, naplatu, fiskalizaciju ili pravila zatvaranja. Kod implementacije posebno obuhvatiti pozivnice, prihvat pozivnice, izbor firme, vlasnika, deaktivaciju članstva, više firmi istog korisnika i vanjske učesnike.

## Stanje objave ovog prijedloga

Korisnik je izričito odobrio nastavak bez board rezervacije i implementaciju faze 1 na mainu. Prava po firmi, odjeli/pododjeli i ispravka superadmin pristupa implementirani su; backend je na mainu i produkciji. Deployment je potvrdio bazu i novu aditivnu migraciju bez seedanja/resetovanja. Frontend je također objavljen; produkcijski asseti s novim poljima i nazivima su provjereni. Backend kod: ce6d964; frontend: 2929ff8. Prošla su 233 regresijska testa i završni ciljani paket od 47 testova, TypeScript i build. Poslovni test prijavljenog produkcijskog superadmina nije izvršen. Finansijske faze 2–4 ostaju samo za pregled; njihov proces nije mijenjan.

Board je pri dosadašnjim pokušajima bio nedostupan. Sadržaj četiri kartice i priloga pripremljen je lokalno. Upis na board nije potvrđen; kod i ovaj prijedlog dostupni su i kroz repozitorij radi pregleda. Detaljne revizije i konačne provjere navedene su u završnom izvještaju isporuke.