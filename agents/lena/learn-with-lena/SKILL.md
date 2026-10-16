# Learn with Lena — razvojni nacrt skilla

Status: implementiran prvi pilot obuke novih korisnika kroz postojeći Messages screen. Širi programi iz ovog dokumenta ostaju plan razvoja. Nacrt se ne učitava kao LLM runtime prompt.

## Implementirani pilot — verzija 1

Akcija `learn` u postojećem Lena welcome izborniku pokreće program `user-onboarding`. Program ima početnu provjeru, tri lekcije (podaci o teretu, provjera dokumenata, praćenje) i završnu provjeru s ponuđenim odgovorima. Slobodan tekst i privitci se ne ocjenjuju. Pozivi nisu dio ovog pilota.

`LenaLearningEngine` određuje korak i ocjenu bez LLM-a. Pogrešan odgovor u lekciji ili završnoj provjeri ne pomiče korak. Pomoć i ponovni pokušaj bilježe se odvojeno; kompetenciju potvrđuje samo samostalan tačan završni odgovor. Pilot provjerava osnovna znanja kroz pitanja, a ne izvršavanje zadataka u aplikaciji.

`LenaLearning` sprema stanje i pokušaje u `lena_learning_sessions`, vezano uz vlasnika i razgovor. Ponavljanje zahtjeva za istu korisničku poruku vraća istu poruku. Nastavak akcijom `learn` zadržava stanje. Obuka prihvata samo vlasnikov privatni Lena razgovor, bez vezanog tereta, drafta ili drugih ljudskih učesnika.

Migracija `2026_10_06_120000_create_lena_learning_sessions_table.php` primijenjena je na lokalnu bazu `freightbook_local` nakon korisnikove potvrde. Na drugim instalacijama potrebna je ista migracija kroz odobreni deployment postupak. Enterprise dodjela, mentorski pregled, službeni kurikulumi ispita i obuka za nove pozicije još nisu implementirani.

## Svrha

Learn with Lena je model vođene obuke kroz razgovor s Lenom. Korisniku pomaže razumjeti posao, uvježbati konkretne zadatke i pokazati spremnost za samostalan rad. Program se prilagođava prethodnom znanju, ciljnoj ulozi, kompaniji i području rada.

## Obavezno korištenje postojećeg sučelja

**Ne praviti novi chat interface. Koristiti postojeći reusable Lena Messages screen i njegove zajedničke komponente.**

Polazne datoteke, relativno prema korijenu projekta:

- `frontend/src/components/views/MessagesView.tsx`: postojeći ekran razgovora.
- `frontend/src/components/lena/useLenaEmbeddedMessages.tsx`: zajedničko prikazivanje Lena sadržaja u porukama.
- `frontend/src/components/lena/LenaEmbeddedCards.tsx`: postojeći obrasci kartica unutar razgovora.
- `frontend/src/lib/useLenaAiChat.ts`: postojeća logika Lena razgovora.

Obuku uključiti kroz postojeći tok razgovora. Lekcije, pitanja, povratne informacije i sažetak napretka prikazivati u tom ekranu. Ako treba nova kartica za vježbu ili napredak, dodati je u postojeći mehanizam prikaza poruka. Ne duplicirati listu poruka, unos poruke, učitavanje povijesti, privitke, streaming, pozive ni upravljanje razgovorima. Pri implementaciji provjeriti stvarne mogućnosti navedenih komponenti i proširiti njihove postojeće ugovore gdje je potrebno.

## Programi obuke

| Program | Ciljna grupa | Cilj |
| --- | --- | --- |
| Upoznavanje aplikacije | Novi korisnici | Naučiti osnovne funkcije kroz praktične zadatke u aplikaciji. |
| Enterprise onboarding | Novi radnici kompanije | Naučiti interne procedure, odgovornosti, dokumentaciju i tokove rada. |
| Priprema za carinski ispit | Špediteri i kandidati | Usvojiti gradivo i uvježbati zadatke prema potvrđenom programu ispita za odabranu državu. |
| Prelazak na novu poziciju | Postojeći radnici | Nadograditi postojeće znanje za novu ulogu u logistici, špediciji ili računovodstvu. |
| Obuka drugih uslužnih kompanija | Srodne djelatnosti | Koristiti zajednički model obuke uz sadržaj prilagođen djelatnosti i kompaniji. |

Learn with Lena je obuka korisnika i zaposlenika. Postojeći administratorski `training` mod služi održavanju ponašanja AI-ja; ova dva procesa moraju ostati jasno razdvojena.

## Tok učenja

1. Utvrditi cilj, trenutnu i ciljnu poziciju, iskustvo, jezik i raspoloživo vrijeme. Za carinski ispit utvrditi državu, ispit i potvrđeni program.
2. Provesti kratku početnu provjeru znanja i identificirati nedostatke.
3. Predložiti plan s modulima, ciljevima, praktičnim vježbama i kriterijima prolaska.
4. Voditi kroz kratke lekcije: objašnjenje, primjer, zadatak, odgovor korisnika i konkretna povratna informacija.
5. Kod pogreške objasniti uzrok, ponuditi pomoć i zadati sličnu vježbu. Odvojeno bilježiti samostalan odgovor i odgovor uz pomoć.
6. Periodično ponavljati slabije savladane teme i prilagoditi sljedeću lekciju rezultatima.
7. Završiti provjerom i sažetkom: savladane kompetencije, preostali nedostaci i preporučeni sljedeći koraci.

Lena postavlja jedno jasno pitanje ili zadatak odjednom. U vježbi prvo omogućava korisniku da pokuša, pa tek onda daje rješenje. Završena lekcija i dokazana kompetencija nisu isti podatak.

## Početni sadržaj

- Aplikacija: rad s osnovnim modulima, unos podataka, dokumenti i komunikacija.
- Logistika: planiranje transporta, praćenje pošiljaka, dokumentacija, troškovi i rješavanje operativnih problema.
- Špedicija i carina: deklaracije, tarifno razvrstavanje, porijeklo, carinska vrijednost, postupci i dokumentacija, prema odabranoj jurisdikciji.
- Računovodstvo: dokumentacija, fakture, knjiženja, usklađivanja i kontrole, prema ulozi i lokalnim pravilima.
- Kompanijski sadržaj: odobrene procedure, opisi poslova, radne upute i primjeri koje dostavlja ovlaštena osoba kompanije.

Ovo su područja za izradu kurikuluma, a ne tvrdnja da je službeni ispitni program već definiran. Pravni i računovodstveni sadržaj mora imati provjerljiv izvor, jurisdikciju, verziju i datum provjere. Promjena izvora zahtijeva pregled povezanih lekcija i pitanja. Interna potvrda obuke ne predstavlja službeno položen carinski ispit.

## Enterprise model

Predložene uloge: polaznik, mentor i administrator obuke. Ovlaštena osoba dodjeljuje program prema radnom mjestu i prati rezultate unutar svoje kompanije. Polaznik nastavlja tamo gdje je stao.

Razdvojiti zajednički nastavni sadržaj od materijala pojedine kompanije. Pristup materijalima, razgovorima i rezultatima mora biti ograničen ovlastima i kompanijom. Vježbe koriste izmišljene ili odobrene anonimizirane primjere. Obuka sama po sebi ne izvršava operativne izmjene, knjiženja, slanje dokumenata ili carinske prijave.

## Predloženi backend podaci

Ovo je prijedlog za kasniju implementaciju, bez kreiranja tablica u ovoj fazi:

- Program i verzija: ciljna uloga, jezik, jurisdikcija, izvori i kriteriji prolaska.
- Modul i lekcija: ciljevi, preduvjeti, sadržaj, vježbe i način ocjenjivanja.
- Upis polaznika: kompanija, program, mentor, rok i status.
- Pokušaj vježbe: odgovor, rezultat, korištena pomoć i povratna informacija.
- Napredak i kompetencije: završene lekcije, potvrđene vještine i preporučena ponavljanja.
- Veza s postojećim Lena razgovorom: program i trenutni korak, uz postojeću povijest poruka.

Backend upravlja pristupom, verzijom programa, napretkom i pravilima ocjenjivanja. Lena ne smije sama proglasiti prolaz mimo definiranih kriterija. Nastavak razgovora mora dohvatiti spremljeno stanje obuke.

## Primjeri očekivanog ponašanja

Izmišljeni primjeri:

- Novi korisnik: „Prvi put koristim aplikaciju.” Lena utvrđuje ulogu i vodi kroz prvi praktični zadatak koristeći postojeći Messages screen.
- Kandidat: „Pripremam carinski ispit.” Lena prvo utvrđuje državu i program ispita, a zatim predlaže procjenu znanja i plan učenja.
- Postojeći radnik: „Iz dispečerske službe prelazim u računovodstvo.” Lena procjenjuje prenosivo znanje i fokusira program na nedostajuće kompetencije.
- Enterprise mentor dodjeljuje interni onboarding. Polaznik dobiva samo odobrene materijale svoje kompanije i nastavlja prethodnu lekciju.

## Faze razvoja

1. Potvrditi prvi program, sadržaj, kriterije prolaska i uloge.
2. Definirati backend ugovore i povezivanje obuke s postojećim razgovorima.
3. Implementirati jedan cjelovit program: procjena, lekcija, vježba, ocjenjivanje i nastavak nakon prekida.
4. Dodati dodjelu programa i mentorski pregled za enterprise.
5. Proširiti kurikulume za carinski ispit, nove pozicije i druge djelatnosti.

## Kriteriji prihvata

- Obuka koristi postojeći Lena Messages screen; nema paralelnog chat sučelja.
- Polaznik može započeti, prekinuti i nastaviti program bez gubitka napretka.
- Netočan odgovor vodi do objašnjenja i ponovne vježbe; prolaz prati definirane kriterije.
- Materijali i rezultati jedne kompanije nisu dostupni drugoj kompaniji.
- Priprema ispita vezana je uz potvrđenu jurisdikciju, program i izvore.
- Korisnički tekstovi slijede postojeći sistem lokalizacije.
- Promjene skilla dokumentiraju se novim zapisima u `training/`; razvojni nacrt se ne registrira automatski kao runtime skill.

## Otvorene odluke za sljedeću iteraciju

Prvi pilot program, trajanje lekcija, kriteriji prolaska, službeni izvori za odabrani carinski ispit, ovlasti mentora i politika čuvanja rezultata. Ove odluke ne sprečavaju izradu ovog nacrta.

## AI obuka � aktivni tok (2026-10-06)

Aktivni Learn with Lena vi�e ne koristi fiksni ciklus assessment � lesson � final s istim pitanjima. Fiksni engine i pitanja sa�uvani su u komentarima radi referencije.

`LenaLearningTutor` koristi postoje�i OpenRouter servis i `LegalSourceCatalog`. AI bira najvi�e dva relevantna dokumenta iz kataloga; backend provjerava ID-ove i �alje stvarne PDF dokumente. Bez relevantnih dokumenata Lena tra�i cilj/jurisdikciju umjesto pravnog ocjenjivanja iz memorije. AI prvo utvr�uje ulogu, cilj i dr�avu, zatim kreira razli�ite zadatke, ocjenjuje slobodne odgovore i prilago�ava sljede�u temu. Propisi nisu AI-izmi�ljena pravila: zakonske tvrdnje moraju imati izvor, �lan/stranicu i datum preuzete verzije.

Obuka i dalje koristi postoje�i Messages ekran i obi�ne poruke. Razgovor �uva plan i odgovore; session v2 �uva broj obra�enih poteza, izvore i raniji pilot napredak. Stare session v1 nadogra�uju se pri sljede�em odgovoru, bez migracije ili brisanja razgovora. Ponovljeni zahtjev za isti odgovor vra�a postoje�u poruku. Gre�ka generiranja ne zapisuje novi napredak. AI povratna informacija nije slu�bena certifikacija ni dokaz aktualnosti propisa. Trenutni katalog pokriva prvenstveno carinske teme; za druge oblasti treba dopuniti odgovaraju�e izvore.
