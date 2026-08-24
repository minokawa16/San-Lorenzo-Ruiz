# Canonical TUGON database migrations

This directory is the only active source of database schema changes.

Rules:

1. Migration filenames use `NNN_lowercase_description.sql`.
2. Applied migration files are immutable; create a new migration for every later change.
3. Run migrations only from the command line with `php database/migrate.php up`.
4. Existing databases must first be reviewed and marked with `php database/migrate.php baseline`.
5. A clean database uses `php database/migrate.php up`; migration `000` creates the baseline schema.
6. Never place `CREATE TABLE` or `ALTER TABLE` in a browser-facing page.
7. Back up the database and test the migration against a copy before changing shared data.

The older SQL files under `database/migrations/` are retained only as historical inputs. They are not executed by the canonical runner.

