# TUGON Deployment Hardening Completion

Date: 2026-08-21  
Scope: Code and local development database hardening only. No hosting account, domain, production database, or external service was changed.

## Completed blockers

- Production configuration fails closed without HTTPS `APP_URL`, database credentials, encryption key, or JWT secret.
- `APP_BASE_PATH` and `APP_URL` replace the hardcoded production-path assumption while preserving local `/ParishSystem/` behavior.
- Production passwords require at least 12 characters; administrator MFA remains mandatory.
- The known administrator credential is flagged for mandatory replacement on the next authenticated session.
- Apache denies direct HTTP access to uploads, storage, backups, logs, configuration, database scripts, implementation directories, and dependencies.
- Directory listing, server signatures, trace requests, and PHP error display are disabled in the production container.
- OCR health diagnostics require an authenticated administrator with completed MFA and no longer return raw exception details.
- Local uploads, storage, dependencies, logs, backups, tests, and development diagnostics are excluded from the Docker build context.
- Canonical migration SQL is explicitly retained in the image.
- Composer dependencies, including the OCR wrapper, are installed from the lock file in a dedicated build stage with production autoloading.
- A container health check uses the non-sensitive database health endpoint.
- Persistent runtime paths are owned by the web account with restricted permissions.
- A non-root background-worker command runs notification delivery, reservation reminders, and announcement lifecycle tasks.
- The notification runner uses a database advisory lock in addition to delivery idempotency.
- Fixed password-repair hashes and the legacy default administrator insert were removed.
- `.env.example`, a production readiness CLI gate, automated deployment checks, and a deployment runbook were added.

## Recovery point

- Backup: `C:\xampp\htdocs\ParishSystem\backups\TUGON_pre_deployment_hardening_20260821-053339.sql`
- SHA-256: `67066D57D18216349EF086109D6029E2393862823BC7799033A3D03556AFB314`
- Size: 340,840 bytes

## Verification

- PHP syntax: 241 project files, 0 errors.
- JavaScript syntax: 6 files, 0 errors.
- Deployment implementation checks: 14/14 pass.
- Static production readiness checks: 15/15 pass.
- Full production-like readiness gate with isolated least-privilege database user: 29/29 pass.
- Composer validation: pass; locked dependency dry run: pass; vulnerability advisory check: no known advisories returned.
- Docker entrypoint and worker shell syntax: pass.
- Protected OCR route: unauthenticated response is HTTP 401.
- Authenticated responsive/accessibility browser matrix: 64/64 pass.
- Phase 11–15: 24/24; AI evaluation: 24/24; Phase 2 authentication: pass; Phase 3 security: 0 failures; Phase 7 core/integration: 19/19; Phases 8–10: 31/31.
- Notification, reservation-reminder, and announcement worker commands executed successfully against a disposable database clone.

## Hosting-only acceptance still required

These are not code defects and cannot be completed without the chosen hosting/domain accounts:

1. Enter real production secrets from `.env.example` in the hosting provider.
2. Change the administrator password when prompted; use 16+ unique characters where practical.
3. Configure and test the Gmail Google App Password with a real OTP.
4. Provision a least-privilege production MySQL user and take a provider backup.
5. Mount persistent storage at `TUGON_DATA_DIR`.
6. Deploy exactly one worker service using command `tugon-worker`.
7. Build the Docker image in CI/hosting and run the staging acceptance checks. Docker Engine was not available on this workstation, so the complete Linux image build must be verified there.
8. Connect the domain, enforce HTTPS, and verify private-path 403 responses through the real proxy.
9. Run migrations 000–011 and `php database/production-readiness.php --startup` against production.
10. Complete a real backup-restore drill and final human accessibility acceptance before opening registration to the public.

The system should remain a private staging deployment until every hosting-only acceptance item passes.
