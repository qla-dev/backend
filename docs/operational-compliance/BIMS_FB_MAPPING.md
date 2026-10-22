# BIMS ↔ FreightBook
ops_inspection_cases.order_id → postojeći ops_orders.id; company_id mora odgovarati nalogu. Posredno workspace_id/load_id ostaju postojeće veze. Ne pravi se drugi TMS ili kopija Shipment entiteta.

Polja: control_type, local_key (lokalna deduplikacija), status, external_reference, authority, responsible_user_id, next_action, due_at, document_ids, item_outcomes, note, revision, recorded_by. Lokalni ključ se ne predstavlja kao službena referenca. Promjene su u compliance_audit.

GET accounting/{company}/ops/orders/{order}/compliance čita checklistu, predmete, dokumente i audit metapodatke.
POST .../inspections evidentira lokalni predmet; id+revision ažurira postojeći. Službeni događaj bez dokaza odbija se.
POST .../compliance-documents povezuje vlastiti dokument s nalogom i sprečava duplu vezu. Lični dokumenti i dokumenti drugih tereta ne mogu se prenositi tim putem.

Dokument koji se koristi kao inspekcijski dokaz ne može se zamijeniti ili obrisati kroz generički dokument API; nova verzija je nova datoteka.

A: ručna evidencija je implementirana. B: podržan datotečni uvoz/izvoz čeka potvrdu formata. C: API sinhronizacija čeka ovlašten ugovor; ne postoje izmišljeni endpointi.
