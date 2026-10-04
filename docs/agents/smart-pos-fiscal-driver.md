# Agent note — Smart POS fiscal driver and PANTHEON connector

Status 2026-10-04: Smart POS UI, API, database and tests are done. **The fiscal driver worker is missing.** Nothing can be printed until it is installed.

## 1. What is missing: the Laravel fiscal worker

`qla-dev/invoice-maker` (reference copy: `../artifacts/invoice-maker-reference`) did not talk to the device itself. It ran `php artisan fiscal:*` in a **separate Laravel project** (`LARAVEL_APP_DIR=../laravel`). That project drives the Datecs device (FPAX / Datecs spooler, request file + answer file over a COM port). The user does not have that repository on this PC. The driver is not installed here either.

Smart POS calls it through `App\Services\Fiscal\FiscalBridge`, the same way as `runLaravelArtisan()` in `invoice-maker/server.ts`:

```
FISCAL_PHP_BIN=php
FISCAL_WORKER_DIR=C:\path\to\fiscal-laravel   # folder containing its artisan
FISCAL_TIMEOUT=90
FISCAL_OPERATOR=1
FISCAL_TAX_LABEL_17=E                          # device tax label for 17 % VAT
FISCAL_TAX_LABEL_0=K                           # device tax label for VAT-exempt
```

Confirm the tax labels against the device programming before going live. A rate without a label is refused; it is never sent as tax-free.

### Commands the worker must provide

All commands print one JSON object to stdout. On failure they exit non-zero with JSON `{ "ok": false, "error": "...", "detail": "..." }`.

| Command | Exists in invoice-maker | Used for |
|---|---|---|
| `fiscal:probe` | yes | Status badge. Returns `{ timestamp, likelyConnection: { reachable, port, name, reason }, candidates: [...] }` |
| `fiscal:dnevni-izvjestaj --report-type=0 --timeout=N [--preview]` | yes | Daily (Z) report. Datecs `69,1,______,_,__;0;` |
| `fiscal:presjek-stanja --report-type=2 --timeout=N [--preview]` | yes | Status snapshot (X). Datecs `69,1,______,_,__;2;` |
| `fiscal:stampaj-duplikat --fiscal-number=N --timeout=N [--preview]` | yes | Duplicate, Datecs command 109 `recordType=F` |
| `fiscal:test-paper --line=... --timeout=60` | yes | Non-fiscal test and non-fiscal text (38 / 42 / 39) |
| `fiscal:fiskalni-racun --request=<json file> --timeout=N` | **new** | Fiscal receipt for an issued outgoing invoice |
| `fiscal:reklamirani-racun --request=<json file> --timeout=N` | **new** | Refund receipt referencing the original fiscal number |
| `fiscal:periodicni-izvjestaj --from=Y-m-d --to=Y-m-d --timeout=N` | **new** (dummy in invoice-maker) | Periodic report |

The new commands must return `fiscalNumber` (positive integer) on success. They should also return the same `command`, `requestFile`, `answerFile`, `rawAnswer` and `bridgeSettings { model, comPort }` fields as the existing commands.

`--request` JSON (built by `SmartPos::receipt()`):

```json
{
  "invoice_number": "INV-1-000012", "operator": "1", "currency": "BAM",
  "payment": { "method": "cash|card|cheque|transfer|voucher", "amount": "117.00" },
  "total": "117.00",
  "lines": [{ "name": "max 32 chars", "quantity": "2.000", "price": "58.50", "total": "117.00", "tax_label": "E", "tax_rate": "17.00" }],
  "buyer": { "name": "...", "tax_number": "JIB/IDB", "address": "..." },
  "original_fiscal_number": 1084, "original_fiscalised_at": "...", "reason": "..."   // refund only
}
```

Prices are gross (VAT included), computed from the immutable issued snapshot.

### Safety rules already enforced (do not weaken)

- Smart POS fiscalises the **existing** invoice (`invoices.fiscal_*` columns). It never creates a second invoice. Only an issued outgoing BAM invoice that is not a corrective invoice can be fiscalised.
- The invoice is set to `pending` before device I/O. The device call runs outside DB transactions.
- Worker error (exit code / JSON error): `failed`, retry allowed. Timeout, or success without `fiscalNumber`: `unconfirmed`. Retry is blocked; the cashier enters the printed number via "Potvrdi odštampan račun".
- Every call is logged in `accounting_fiscal_operations` with a unique `request_key` per company. A repeated request does not print again.
- Abilities: `pos` for all fiscal actions, granted explicitly by the company owner.

### When the worker is found

1. Put the worker folder on the server/PC with the device. Install its driver (Datecs FPAX / spooler and the COM port driver for the model).
2. Set `FISCAL_WORKER_DIR`, then run `php artisan fiscal:probe` inside the worker.
3. Implement the three **new** commands above, if the worker does not have them yet.
4. Test with `--preview` and "Nefiskalni test" before issuing a real receipt.

## 2. PANTHEON connector (Company details → Integracije; architecture: pantheon-integration.md)

- The licence is the company's PANTHEON SQL Server login (host, port, database, schema, username, password). The password is stored with `Crypt::encryptString` and never returned to the browser.
- The server PHP needs the `pdo_sqlsrv` extension. The XAMPP PHP on the developer PC does not have it. Trendy's server does.
- Mapping was verified read-only against Trendy's `BA_TRENDY` on 2026-10-04:
  - Chart of accounts: `tHE_SetAccount(acAcct, acName, acPermitPost [D/N/K/O], acSubject 'T' = partner required)`
  - Subjects: `tHE_SetSubj(acSubject key, acName2, acCode = tax ID, acRegNo, acAddress, acPost, acCountry, acVATCodePrefix = ISO country, acActive)`
  - VAT codes: `tHE_SetTax(acVATCode, acName, anVAT, acFiscalCode, acActive)`, shown as reference only
  - GL: `tHE_AcctTrans(acKey, acDocType, adDate, anClerk, anDebit, anCredit, acNote, acKeyView)` + `tHE_AcctTransItem(acKey, anNo, acAcct, acSubject, anDebit, anCredit, acDoc, adDateDoc, adDateDue, adDateVAT, acCurrency 'KM', anFXRate, anValDebit, anValCredit, acNote)`. `anQId` is an identity column; the QId columns are filled by an INSTEAD OF insert trigger.
  - Key: `yy + docType(4) + 7-digit sequence` (`2642000000017`), view `26-4200-000017`. Doc types in Trendy: 4200 izdate fakture, 4300 primljene fakture, 4700 temeljnica.
- Import is insert-only and never redefines a local account or partner. Classes 7–9 need an explicit account type.
- Export always runs a dry run first. Writing needs `allow_write` on the connector plus the `post` ability. The header `acNote` carries the marker `SF:<company>:<entry>`, so a retry relinks instead of writing twice.
- Not done yet: two-way sync of PANTHEON postings back into SmartFreight; open-item (IOS) matching.
