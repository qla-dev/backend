# My Fleet, daily courier tours and shared order intake

Approved by the user on 12 November 2026 for implementation and direct delivery to main.

## Delivered behavior

- My Fleet opens the existing condition / photo / review wizard independently of a load. Custody can pass to another company driver, a depot, a workshop, or back to a rental lessor. These handovers do not finish loads.
- The existing route-end return still closes its load and also records a fleet handover when the actor has authority over that vehicle. A customer completing a partner load cannot change the partner's vehicle custody.
- Handovers await dispatcher review in red. Confirmation, a new damage case, or an explicitly linked existing damage case completes the review in green. Technical dispatch blocking is independent.
- Damage cases retain optional police / insurance references, driver statements, participant / vehicle / property descriptions, contacts, company decisions, costs, resolution and private attachments. Closing requires a resolution, not police or insurance details. Closing a damage case never automatically clears a vehicle block or deducts money from a driver.
- Company-scoped damage notifications appear in the main bell and in My Fleet. Drivers cannot access internal damage-case processing.
- Global Tracking has separate Loads and Daily tours views. Daily tours use the existing Freightbook DataTable and group by day / depot / partner. Open records are yellow; locked records retain the vehicle plate and assignment evidence plus actor and time.
- Valid driver assignments continue across service days. Locking after a reviewed depot return uses that day's actual assignment. Pending entry / exit inspections, incorrect driver / vehicle pairs, and unavailable vehicles prevent unsupported closure. Reopening requires a reason and retains the previous snapshot in the audit trail.
- XLSX, XLS, CSV and TSV imports support sheet / column selection, manual tour-ID entry, explicit service date, date filtering and read-only preview. Missing IDs and duplicates are visible. Confirmed imports are serialized and deduplicated per company / day / depot / partner / tour ID; leading zeroes are retained.
- Lena can suggest bounded column indexes using the active shared fleet-tour-import skill. The dispatcher reviews them. Confirmed mappings are retained per company / partner / header structure. Driver names are suggestions, never automatic assignments or locks.
- Daily tour history is paginated with date, depot, partner and status filters and has no automatic deletion. At least a year's history is supported.
- Vehicle states cover depot, in use, workshop, for sale, sold, returned and stolen. Existing ownership options are retained. Rental returns require a handover; archived assets disappear from normal vehicle selection but remain in fleet history.
- New order / inquiry uses PostLoadModal's existing screens. OPS saves an internal order or an incomplete inquiry through its existing intake API, without publishing an exchange load. Extended shipment data remains visible after saving. External-order import remains available. Public load drafts cannot be created from the internal save-draft button; incomplete internal work is saved as an inquiry.

## Schema and rollout

`2026_11_12_000001_create_fleet_operations.php` adds fleet handovers, assignments, damage cases, private file records, tours, audit events, notifications and saved import mappings, plus depot and blocking fields on vehicles. It retains old condition reports and references their existing private photos. Existing driver assignments receive a baseline at migration time; earlier custody is not fabricated.

Apply the additive migration through the normal reviewed deployment process before serving the new fleet API and UI. This work did not run a migration, reset, seed or data rewrite against a production database. Routine rollback intentionally refuses to erase operational fleet history. No package-level partner routing integration is claimed; partner detail links remain external.

Lena recognition requires the existing configured OpenRouter integration. Manual mapping remains available when AI recognition is unconfigured or fails. AI transport is verified with mocked responses; no live billable AI recognition test was performed.

## Verification

Backend regression tests run only with an explicitly verified SQLite `:memory:` connection. Coverage includes custody transfers, pending and confirmed review, separate damage closure, existing-case linking, private file upload and company isolation, rental returns, reviewed end-of-day depot returns, mismatched assignments, duplicate imports, archived-day retrieval, AI mapping validation and partner-vehicle custody preservation. Frontend parsing tests cover leading zeroes, date ambiguity, selected-day filtering and arbitrary partner columns.

TypeScript and the Vite production build are checked. Browser checks use synthetic data on localhost, covering handover review, internal order screens, damage processing, import preview, responsive tables and light / dark views. These are not production business acceptance tests. Exact final counts and commit IDs are recorded in the delivery report.
