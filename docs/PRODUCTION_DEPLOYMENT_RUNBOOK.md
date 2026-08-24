# TUGON Production Deployment Runbook

This runbook prepares a new environment. It does not authorize copying local development data into production.

## 1. Before building

1. Change every administrator password that has been shared or used for development.
   Use at least 12 characters; 16 or more is recommended for administrators.
2. Confirm the active administrator email is verified and can receive OTP messages.
3. Generate independent secrets:

   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

4. Copy `.env.example` into the hosting provider's secret-variable interface. Never upload a populated `.env` file.
5. Create a least-privilege MySQL user for only the TUGON database. Do not use `root`.

## 2. Required services

- Web service: build the repository Dockerfile and use its default command.
- MySQL service: private network access, automated backups enabled.
- Persistent volume: mount at `/var/www/tugon-data` and set `TUGON_DATA_DIR` to that path.
- Worker service: use the same image with command `tugon-worker`, the same environment/volume, and exactly one replica. A database advisory lock provides additional duplicate-run protection.

Only one service should run schema migrations. For the first controlled release, either run `php database/migrate.php up` as a one-off command or temporarily set `TUGON_RUN_MIGRATIONS=true` on a single web instance. Set it back to `false` after the migration succeeds.

## 3. Gmail MFA configuration

Enable two-step verification on the Gmail account and create a Google App Password. Store the app password as `MAIL_PASSWORD` (or `GMAIL_APP_PASSWORD`). Never use the normal Gmail login password.

Required values:

```text
MAIL_ENABLED=true
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=tugonparish@gmail.com
MAIL_PASSWORD=<Google App Password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=tugonparish@gmail.com
```

## 4. Database release

1. Take a provider snapshot/backup.
2. Run `php database/migrate.php status`.
3. Run `php database/migrate.php up` once.
4. Run `php database/migrate.php status` again and confirm 000–011 are `APPLIED`.
5. Run `php database/production-readiness.php --startup`.

The migration runner uses a database lock and checksum validation. Never edit an already-applied migration.

## 5. Domain and proxy

- Set `APP_URL` to the canonical HTTPS URL without a trailing slash.
- Set `APP_BASE_PATH=/` when served at the domain root, or the actual prefix when served below one.
- Set `ALLOWED_ORIGINS` to the canonical HTTPS origin.
- Ensure the proxy supplies `X-Forwarded-Proto: https`.
- Redirect HTTP to HTTPS at the hosting/domain layer.

## 6. Required acceptance checks

Run these before making the domain public:

1. `GET /healthz.php` returns 200 without database details.
2. Direct requests to `/uploads/`, `/storage/`, `/backups/`, `/logs/`, `/config/`, `/database/`, `/vendor/`, and `/includes/` return 403.
3. An unauthenticated request to `/ocr/health.php` returns 401.
4. Admin login requires OTP and the OTP arrives through Gmail.
5. A queued notification is processed by the worker exactly once.
6. A reservation reminder and announcement lifecycle event run through the worker.
7. Upload a test requirement, restart/redeploy the web container, and confirm the file remains on the volume and is accessible only through its authorized controller.
8. Generate, verify, and download a test certificate.
9. Restore the latest backup into a disposable database and confirm it is usable.
10. Run the responsive/accessibility and core regression suites against staging.

## 7. Rollback

Keep the previous image tag and the pre-migration database snapshot. Roll back code and database together when a migration-dependent release must be reverted. Preserve the persistent volume; do not delete it during an application rollback.
