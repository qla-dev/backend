# Backend database safety — mandatory

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
