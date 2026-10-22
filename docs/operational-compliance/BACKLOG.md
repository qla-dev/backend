# Preostali rad prije zajedničke integracije/pilota
P0: pregled i cherry-pick/diff integracija s tačnom aktuelnom granom stvarnog projekta; ova lokalna kopija može biti starija od udaljene. Backup i migracijski pregled; nisu mijenjane postojeće baze.
P0: kompletirati pravnu matricu BA/HR/RS, uključujući sve izmjene i teritorijalne izuzetke, osnov/rokove čuvanja, brisanje i prigovore. Nacrt nije prihvat regulatorne usklađenosti.
P0: ovlašten BIMS ugovor za bilo koju automatsku sinhronizaciju; ručna evidencija radi nezavisno od njega.
P1: povezani postupak dopune/prigovora umjesto novog zahtjeva; raspored obnova i notifikacije; operativni postopak kod isteka.
P1: dodatni negativni E2E testovi s više stvarnih korisničkih uloga u izolovanoj testnoj instanci i browser test upload/download kroz stvarni backend.
P1: formalni postupak ponovnog otvaranja i usklađenje s PANTHEON zaključavanjem; automatizovana invalidacija svih relevantnih promjena iz drugih postojećih ekrana.
P2: proširenje uslovnih šablona, mobilni E2E, pravila većih OPS Today lista i deduplikovane notifikacije.

Ne pokretati migraciju/seed/deploy nad produkcijom na osnovu ovog paketa. Ovaj paket je lokalna implementacija i dokazi provjera, ne javna objava ili puna vanjska integracija.
