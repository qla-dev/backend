# Fleet depots and scoped vehicle workspace — 17 November 2026

The user approved implementing the reference-image additions before publishing both repositories and production. The shared board was unreachable at all three known addresses; the user explicitly authorised editing and pushing without reservations for this release.

## Delivered changes

- The existing FleetView opens a Freightbook workspace for the selected company, retaining existing PageHeader, Card, DataTable, Dialog, Button, primary tokens and light/dark styling. Independent drivers retain their original fleet view; the documents/statistics view remains reachable.
- Company/depot scope, canonical status counters including archived assets, paginated vehicle search, current tours, last recorded mileage and existing service data. Sidebar-style vehicle detail contains overview, handovers/damage, documents, service and GPS when actual data exists.
- Registered depots with stable company-scoped codes, names, optional addresses/cities and deactivation. Home depot is separate from operational depot and GPS. Transfers of assigned vehicles use handovers, preserving historical snapshots.
- Explicit fleet manager, depot manager, dispatcher, driver-depot and workshop grants. Company owners/platform administrators manage grants. Grants require active membership; driver grants cannot elevate drivers. Existing manager/dispatcher company-wide rights are preserved explicitly by migration; new members require an assignment.
- Central FleetDepotAccess applies to context, overview, historical handovers, damage cases, tours, private files and notifications. Historical cases use their original depot. Old generic vehicle, document, GPS and fleet-access endpoints also enforce the boundary, including both sides of a document reassignment.
- Workshop access is limited to explicitly assigned vehicles. Workshops can record service details and change a parked repair vehicle between maintenance/depot; they cannot clear a dispatch block, assign drivers, handle internal damages or expand access.
- Four-step handover using the existing condition/photo/review workflow, with destination cards and registered depot selection. Standalone handover continues to leave a load open. Rental return, review and damage processing remain separate.
- Tour import uses stable depot identity and remains deduplicated after depot renaming. Historical imported names are retained. Courier tours remain in Global Tracking. This release does not add partner package-routing or telemetry integrations.

## Migration and release

The new migration is additive: `2026_11_17_000001_add_fleet_depots_and_access`. It does not overwrite the delivered 12 November migration, erase history, reset/seed the database or write accounting data. Exact existing depot names are imported by company with deterministic legacy codes. Unknown historical locations are not inferred from today's vehicle. Home depot stays unassigned until explicitly chosen.

`freightbook:prepare-fleet-release --inspect` performs a read-only preflight against effective database configuration, verified local database host, configured/actual database match, finite deployment-site allowlist, prerequisite tables and migration history. Public logs expose a target fingerprint, never credentials or the database name. Partial/unregistered schema is refused. The apply mode executes only the two approved fleet migration files. Routine rollback refuses to delete operational history.

The backend redeploy invokes inspection and preparation before the frontend is published. A redeploy request already executing an older version of redeploy.php may need a second invocation to execute the updated fleet steps. Require the explicit `Fleet schema ready` result before publishing the frontend. Never use a generic force/reset/seed command.

## Verification and limits

- Final backend suite: 283 executions / 2,135 assertions, verified SQLite `:memory:` only. Includes inherited regression cases and new depot, file reassignment, grant revocation, workshop, driver-scope and read-only release-preflight tests.
- TypeScript and production Vite build passed; four importer parser tests are part of final release verification. Existing bundle-size and font-import warnings remain.
- Synthetic browser review covers overview/detail, depot/access editor, four-step handover, light/dark themes and a 390px viewport without page-wide horizontal overflow. No production users or business records were used in these visual fixtures.
- The existing tour assignment evidence is day-based; this release does not claim interval/segment-level reconstruction of multiple custody changes during one tour. No new service scheduler, police/insurance integration or automatic deduction is introduced.
- Production commit IDs, deployment completion and public/private-route checks are recorded in the session delivery report. An authenticated production handover is not created merely to test deployment.
