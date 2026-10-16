# Shared agent coordination

This workspace can be edited simultaneously by Codex, Claude and Copilot.
Before modifying files, read the shared board:

```powershell
python D:\FreightBook\tools\agent_board.py --board-dir D:\FreightBook\.agent-board status
```

Use a unique session ID, such as `codex-api`, `claude-models` or `copilot-routes`.
Create a task and claim files **before editing them**:

```powershell
python D:\FreightBook\tools\agent_board.py --board-dir D:\FreightBook\.agent-board create my-task --agent codex-api --title "Concrete task"
python D:\FreightBook\tools\agent_board.py --board-dir D:\FreightBook\.agent-board claim my-task --agent codex-api --scope backend/app/Http/Controllers/ExampleController.php
```

## Board rules

- Claim files before editing. Directory claims cover all descendants.
- Refresh leases every 10 min: `heartbeat --agent YOUR_ID`.
- Reserve `@git-write` before git operations.
- `finish` puts task in **Ceka test**, not Done. Test separately with `pass` / `fail`.
- Post a summary at the end of each task.
- Attach evidence: `screenshot`, `record`, `attach`.

## Dashboard

Central board: `http://api.bosancica.ai:82` (or `http://127.0.0.1:8001`).
Login with your credentials (ask admin for an account).

---

# Backend database safety - mandatory

The configured database may contain live or irreplaceable data. Never assume that an environment name such as `testing`, a CLI `--env=testing` argument, or `phpunit.xml` makes an Artisan command safe.

- Never run `php artisan migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, destructive rollbacks, table truncation, mass deletion, or equivalent destructive database commands in this workspace.
- Never run `php artisan db:seed`, `migrate --seed`, a seeder, or any command that may rewrite database records unless the user explicitly requests that exact operation after being told which database will be affected.
- Do not use `--force` to bypass Laravel production safeguards for database operations.
- Before any command that can write to a database, first perform a read-only verification of the effective connection, including driver, host, port, and database name. Account for environment files, process environment variables, and cached Laravel configuration.
- If the effective host is remote, or the database cannot be proven to be disposable and isolated, stop and do not run the command.
- Run automated backend tests only with SQLite `:memory:` by explicitly setting `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` for the test process, unless the user explicitly authorizes another isolated test database.
- The absence of `.env.testing` is a hard stop for any Artisan command using `--env=testing`; Laravel may fall back to the ordinary `.env` connection.
- Verify migrations only with non-destructive checks or an isolated in-memory database.
- Seeders must not be part of normal production deployment and must not delete or replace existing operational records.
- If a database command unexpectedly targets a non-isolated database, terminate immediately and report the exact command and effective connection. Do not attempt an automatic repair, reseed, or recovery.

# Open work — Smart POS fiscal driver

Smart POS fiscalisation depends on an external Laravel `fiscal:*` worker and a Datecs driver, neither of which is installed yet. Read `docs/agents/smart-pos-fiscal-driver.md` before touching `App\Services\Fiscal` or the PANTHEON connector.

# SmartFreight and PANTHEON (architecture)

SmartFreight works on its own; PANTHEON is only a connector. Records are created locally and a scheduled sync pushes them to PANTHEON. Never write PANTHEON tables directly from a user action. Read `docs/agents/pantheon-integration.md` before changing Accounting sync, CRM, Smart POS or Ops work orders.
