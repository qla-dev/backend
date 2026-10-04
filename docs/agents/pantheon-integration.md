# Agent note: SmartFreight and PANTHEON

**Read this before touching PANTHEON, Accounting sync, CRM, Smart POS or Ops work orders.**

## The one rule

SmartFreight is a complete system on its own. PANTHEON (Datalab ERP) is only a **connector**.

- Every record is created and edited in SmartFreight's own database.
- When a company connects PANTHEON, a **sync** pushes our records to PANTHEON and pulls back only what PANTHEON owns.
- No user action, button or API endpoint ever writes PANTHEON tables directly. New features get a local table plus a sync step.

This is the difference from the Trendy project (`C:\Users\Public\Documents\trendy`): Trendy cannot work without PANTHEON, while SmartFreight can.

## Where things live

| Area | Local data (master) | Sync to / from PANTHEON | Code |
|---|---|---|---|
| Connection ("licence" = SQL login) | `accounting_pantheon_connectors` (password encrypted) | — | `PantheonConnector` |
| UI for the connection | Company details → **Integracije** tab | — | `frontend/.../CompanyIntegrations.tsx` |
| Chart of accounts, partners | `accounting_accounts`, `accounting_partners` | Pull `tHE_SetAccount`, `tHE_SetSubj` (PANTHEON is master); linked in `accounting_pantheon_links` | `PantheonConnector::sync` |
| Journal postings | `accounting_entries` | Push `tHE_AcctTrans` / `tHE_AcctTransItem` (temeljnica 4700, izdate 4200, primljene 4300) | `PantheonConnector::export` |
| CRM offers / orders | `crm_documents`, `crm_document_items`, `crm_contacts`, `crm_follow_ups` | Pull `tHE_Order` 0100/0110/0120 + `tHE_LinkMoveItemOrderItem` (read-only) | `PantheonCrmSync` |
| Work orders (špediterski nalog) | `ops_orders`, `ops_order_items`, `ops_work_logs`, `ops_events`, `ops_order_documents` | Push `tHF_WOEx` / `tHF_WOExItem`; pull closing (Z) | `OpsOrders`, `OpsPantheonSync` |
| Fiscal receipts (Smart POS) | `invoices.fiscal_*`, `accounting_fiscal_operations` | Not PANTHEON: external `fiscal:*` worker | `SmartPos`, see `smart-pos-fiscal-driver.md` |

Everything runs from one scheduled command: `accounting:pantheon-sync`, every 5 minutes. A failure in one part (accounting, CRM, work orders) never stops the others.

## The business loop

```
CRM offer (SmartFreight lead/offer, or pulled from PANTHEON)
  → "Pošalji u tracking": the Post load form opens prefilled; the load keeps crm_document_id
  → carrier offer accepted → shipment_workspace booked
  = work order (ops_orders, 1:1, created by ShipmentWorkspaceOpsObserver in the booking transaction)
      lines come from the company's service template: cost / operation / revenue
  → shipment in_execution → work order in_progress; CRM stage "in_delivery"
  → shipment completed → "delivered" event; CRM stage "delivered"
  → outgoing invoice in Accounting ("Kreiraj iz posla" = from the workspace)
  → close work order: links the revenue and cost invoices, computes the margin;
      missing POD or actual cost → partially_closed
  → CRM stage "invoiced" once the linked invoice is issued
  → syncs: work order → tHF_WOEx, postings → tHE_AcctTrans
```

Spec of the Ops module: `docs/pantheon-proizvodnja/freightbook_ops_prijedlog.sql` and `freightbook-ops-pantheon-proizvodnja.json` in the workspace root.

## Rules every sync follows

- **Opt-in writing.** Writes only when `accounting_pantheon_connectors.allow_write` is on, plus the module's own switch (e.g. `ops_pantheon_sync.sync_enabled` and `push_orders_from`).
- **Dry run.** Every push has a dry run (`write: false`) that lists what would be written and why something waits, e.g. unknown item code in `tHE_SetItem`, missing template, or write disabled.
- **Idempotent.** Links tables (`accounting_pantheon_links`, `ops_pantheon_links`) plus a marker in `acNote` (`SF:{company}:{entry}` / `SF:{company}:ops:{id}`). A retry relinks, it never duplicates.
- **PANTHEON key format.** `yy + docType(4) + 7-digit sequence`, read under `UPDLOCK`; display key `yy-type-nnnnnn`. `anQId` is an identity column; never write it.
- **Never:**
  - delete in PANTHEON;
  - change a document PANTHEON has closed (work order `acStatusMF = Z`);
  - write 2005/6400/6600/6100 goods documents from Ops (finance goes through the Accounting sync only);
  - write `tHE_Stock` (transport has no stock; warehouse stock is SmartFreight's `warehouse_movements`).
- **Revisions.** Work orders carry `revision`. The sync pushes only revisions PANTHEON has not seen (`ops_pantheon_links.local_revision`).
- **Testing.** Tests run on SQLite `:memory:` with synthetic PANTHEON tables (schema `main`). Look at existing PANTHEON data only with SELECT. Never test-write to a live PANTHEON.

## Permissions (explicit, granted by the company owner)

- `integrations`: the connection and the sync.
- `crm`: CRM.
- `ops`: work orders.
- `pos`: Smart POS.
- `setup`: templates, accounts.
- `post`: pushing postings.

## Open items

- Push SmartFreight-created CRM offers to PANTHEON as `tHE_Order` (0110). Not built yet; follow the same link + marker pattern.
- The Ops sync does not push work logs (`tHF_WOExItemWork`) or events (`tHF_WOExRegOper`) yet.
- The fiscal driver worker is still missing (see `smart-pos-fiscal-driver.md`).
