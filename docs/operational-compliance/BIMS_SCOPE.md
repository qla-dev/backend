# BIMS: opseg i dokazi
IMPLEMENTIRANO LOKALNO: ručna evidencija predmeta unutar postojećeg Ops naloga. Jedan nalog ima više zdravstvenih, fitosanitarnih i kontrola kvalitete. Nije napravljen klon BIMS prijave ili carinski generator.

POTVRĐENO IZ KODA: backend Laravel 12, frontend React 19, ops_orders.workspace_id povezuje nalog s postojećim shipment_workspace. Dokumenti se vezuju postojećom ops_order_documents tabelom. AccountingAccess provjerava aktivno članstvo i ops ovlast; PANTHEON i računovodstvo nisu mijenjani.

HISTORIJSKI DOKAZI: tri FUZIP priručnika v1.1 od 07.05.2023. i inspection-semantic-contract.json. Statusne oznake i semantički podaci nisu potvrđen HTTP ugovor. Originalna arhiva nije mijenjana.

NEPOZNATO: poslovni API, ID šifrarnici, ovlašteni vanjski klijent, request/response, stvarna predaja i prihvat. OIDC discovery nije dokaz ovlaštenja.

Nema slanja, plaćanja, izmjena ili brisanja u stvarnom BIMS-u. Obrada deklaracija ostaje u deklarant.ai; ASYCUDA je odgođena.
