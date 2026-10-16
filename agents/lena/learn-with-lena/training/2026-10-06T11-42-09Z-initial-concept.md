# Training: početni koncept Learn with Lena

- Recorded at: 2026-10-06T11:42:09Z
- Skill: learn-with-lena/SKILL.md
- Status: structure-only
- Source: trenutna korisnička poruka; ID nije dostupan.
- Supersedes: none

## User instruction

Pripremiti backend datoteku za razvoj Learn with Lena: obuka novih korisnika, enterprise radnika, priprema špeditera za carinski ispit i obuka postojećih radnika za nove pozicije. Obavezno koristiti postojeći reusable Lena Messages screen, bez novog chat sučelja.

## Intended behavior

Razvojni nacrt definira programe, tok učenja, enterprise ovlasti, napredak, izvore i kriterije prihvata. Nije registriran kao aktivni skill. Korisnička obuka odvojena je od administratorskog AI training moda.

## Example

Primjeri u nacrtu su izmišljeni scenariji buduće implementacije. Runtime ponašanje nije promijenjeno.

## Main prompt changes

None. Postojeći runtime promptovi i katalozi nisu izmijenjeni.

## Files changed

- `learn-with-lena/SKILL.md`: razvojni nacrt.
- `learn-with-lena/training/TEMPLATE.md`: predložak kopiran iz container recommendation training stabla i prilagođen putanji skilla.
- `learn-with-lena/training/2026-10-06T11-42-09Z-initial-concept.md`: početni zapis.

## Validation

Provjerene postojeće frontend datoteke i korištenje `useLenaEmbeddedMessages` u `MessagesView`. Pregledani backend loaderi: novi nacrt je izvan obrazaca za automatsko učitavanje `skills/*.md`. Nisu pokrenuti aplikacija, AI testovi ni database naredbe. Implementacija obuke je budući rad.
