# Operativna checklista: postojeće stanje
POTVRĐENO U LOKALNOM IZVORU: ShipmentWorkspaceCreator kreira različite stavke za road/sea/air/rail/warehouse. ShipmentWorkspace prilagođava stare liste. ChecklistStatusRequirements ograničava prelaze transporta i POD prozor. OpsOrders povezuje naloge, događaje, dokumente i maržu.

NADOGRADNJA: dodatni operativni panel koristi postojeći shipment_workspace.operational_checklist. Samostalni ručni nalozi bez workspace-a čuvaju listu u ops_operational_state. Ne postoje dvije liste za isti povezani nalog.

Popravljen propust: read accessor dodaje POD/kompatibilne stavke; to nije dokaz da originalna lista postoji. Uslov polaska sada odbija stvarno praznu pohranjenu listu.

Legacy update sada određuje potvrđivača/vrijeme na serveru, eksplicitno bira dozvoljena polja i transakcijski zaključava workspace. Novi panel koristi reviziju i fingerprint radi promjena iz starog interfejsa.
