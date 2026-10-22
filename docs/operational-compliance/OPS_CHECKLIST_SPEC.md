# Operativna checklista: specifikacija
Stavke: key/label, status, responsible_user_id, due_date, next_action, action_value, exception_reason, completed_at/by, confirmation_source, depends_on, applies_when, needs_review i previous_completion.

Statusi: pending, in_progress, waiting_external, blocked, completed, not_applicable. Blokada/izuzetak traži razlog. Nije primjenjivo traži setup ovlast i ne zaobilazi obavezne postojeće shipment provjere. Završetak se serverom pripisuje stvarnom korisniku; AI i upload ne potvrđuju isporuku ili službeno puštanje.

Revizija i fingerprint su obavezni. Nepoznata stavka, tuđa/neaktivna odgovorna osoba i nezavršena zavisnost odbijaju se. Promjena rute/robe/Incoterms/uslova vraća završene provjere na pregled, čuvajući ranijeg potvrđivača u historiji.

Ručno kreirani novi nalozi snimaju važeći neizmjenjivi šablon; objava novog ne prepisuje aktivne naloge. Šabloni imaju uređene zavisnosti (samo prethodne stavke, bez ciklusa) i uslove inspection_required/dangerous_goods/temperature_controlled.

Nalozi s aktiviranom operativnom evidencijom ne zatvaraju se dok primjenjive stavke nisu završene. Označena potrebna inspekcija zahtijeva odobrene predmete. To ne mijenja pravila evidentiranja isporuke, knjiženja ili naplate. Historijski nalozi ne dobijaju retroaktivnu blokadu samom migracijom.
