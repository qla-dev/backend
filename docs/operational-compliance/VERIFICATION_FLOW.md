# Verifikacija profila
Kompanijski vlasnik ili setup ovlaštenik podnosi sposobnost i državu/aktivnost, uz minimalne dokumente ili referencu registra. Driver zahtjev mora imati aktivnog člana kompanije kao subject_user_id. Korisnička rola i sposobnost su odvojene.

Tok: submitted → in_review → verified / supplement_requested / rejected. Verified može postati suspended; suspended ide nazad na in_review. Podnosilac ne može ovjeriti vlastiti zahtjev; review API traži superadmin/master. Svaki pregled traži razlog, metod i važeću reviziju. Dopuna se podnosi kao novi zahtjev, uz očuvanje prethodnog. Formalni povezani žalbeni predmet još nije implementiran.

Istek se računa pri čitanju kao effective_status=expired; historijski status/odluka ostaju. Ne dodjeljuju se nove role niti automatski ukidaju aktivne poslovne obaveze.

Dokazi ostaju u postojećoj documents infrastrukturi. compliance_document_links dodaje kompanijsku zaštitu i SHA-256. Generički API ih ne listira/čita/mijenja/briše. Poseban download provjerava ovlasti i hash te evidentira pristup. Prije verified provjerava se da su dokazne datoteke dostupne i neizmijenjene.

Nema univerzalnog obaveznog KYC/AML ili kopija ličnih dokumenata. Pravna osnova i zadržavanje traže potvrdu po aktivnosti; automatizovan trajni purge nije uključen bez usvojene politike.
