# TUGON Phases 8–10 Completion Report

Date: 2026-08-21  
Scope: development database and application only; no deployment work performed.

## 1. Existing Architecture

TUGON already had separate baptism, confirmation, First Communion, marriage, and funeral tables; browser-rendered certificate previews; basic notifications; and an announcement editor. These paths were retained and connected to authoritative services instead of creating parallel replacement systems.

## 2. Problems Found

- Record pages performed independent direct writes with inconsistent required fields and weak date checks.
- Browser-supplied request IDs were accepted without request type/state or one-to-one linkage validation.
- Official values could be overwritten without correction history, and archive metadata was incomplete.
- CSV import did not stage and preview all row errors before persistence.
- Opening certificate preview created an issuance, number, and verification token.
- Certificate state transitions, immutable PDF snapshots, actual-file hashes, protected downloads, revocation, and linked reissue history were absent.
- Public verification read mutable live record data and treated the former `valid` flag as sufficient.
- Notification category and action routing parsed human-readable message text; deletion was physical.
- Announcement scheduling/audiences were inconsistently enforced, and permanent deletion remained available.

## 3. Changes Implemented

- Added an authoritative sacramental record service used by all five existing admin registry pages.
- Added mandatory field, strict calendar/chronology, duplicate fingerprint, registry uniqueness, and approved-request checks.
- Converted edits to correction requests with field-level previous/new history and privileged application for locked records.
- Added audited archive/restore metadata and the existing Archives screen now restores through the service.
- Added protected, staged CSV validation/preview, transactional confirmation, and formula-safe error reports.
- Made certificate preview non-persistent and visibly marked `PREVIEW — NOT ISSUED`.
- Added controlled draft/review/approval/issue/release/revoke/reissue workflow.
- Added server PDF and QR generation through locked Composer dependencies, immutable snapshots, SHA-256 hashes of actual PDF bytes, protected download integrity checks, and event logging.
- Added minimal public verification from issuance data only; legacy rows without a PDF/hash are not considered valid.
- Added typed/template-based notifications, entity references, whitelisted actions, deduplication, state transitions, preferences, per-channel delivery results, and actual retry processing.
- Refactored reservation and reminder producers onto the typed notification service.
- Added announcement draft/schedule/publish/expire/archive lifecycle, audience resolution, audience-aware notifications, protected attachment authorization, and CLI lifecycle/delivery runners.

## 4. Database Changes

Canonical migrations:

- `008_records_certificates_notifications_announcements.sql`: record lock/archive/correction/import structures; certificate lifecycle/snapshots/events/sequences; typed notifications/templates/deliveries; announcement lifecycle/audiences/attachments.
- `009_phase8_10_workflow_constraints.sql`: one-request-per-record uniqueness; nullable true issuance time; draft/reissue actors and foreign keys.
- `010_notification_template_completion.sql`: reservation and schedule-proposal templates.

All migrations 000–010 are recorded as applied in the development database.

## 5. Workflow Changes

- Records: validate → duplicate/request check → create → correction request → privileged review/apply; archive/restore is reversible.
- CSV: protected upload → row preview → error/warning report → confirm → transactional import.
- Certificates: preview (no write) → draft → review → approval → explicit issue → server PDF/hash/QR + record lock → release; revoke → linked reissue draft.
- Notifications: typed template → in-app state + channel delivery rows → send/fail/cancel → authorized retry.
- Announcements: draft or schedule → audience-aware publish → notification queue → automatic expiry → archive.

## 6. Security Changes

- RBAC permissions gate record, locked correction, certificate issue/revoke, and notification retry actions.
- Every new mutation endpoint enforces CSRF.
- Dynamic table/column identifiers are selected only from fixed server-side maps.
- Request existence, compatible type, approved state, and duplicate linkage are checked server-side.
- Final certificate files and announcement/import files stay below protected upload storage and are streamed through authorized endpoints.
- Certificate downloads recalculate and compare SHA-256 before streaming.
- QR tokens contain no personal data and use 32 cryptographically random bytes.
- Notification action routes use a fixed allowlist; announcement attachments enforce audience access and return 404 on denial.

## 7. Testing Results

- Phase 8–10 integration suite: **31 passed, 0 failed** on a disposable database recreated from the recovery dump plus migrations 008–010.
- PHP syntax checks: **25 changed entry points/services passed**.
- Admin/user page render smoke checks: **9 pages passed**.
- Regression suites: Phase 3 security 7/7; Phase 4 database 5/5; Phase 5 request 6/6; Phase 7 reservation 13/13; Phase 7 proposal/reminders 14/14; Phase 7 resources 4/4; Phase 7 integration 6/6; dashboard/document 5/5; logo asset 4/4.
- Composer installation audit reported no known advisories. A later online re-check was unavailable because network access was blocked; the lockfile remains pinned.
- Disposable test database was removed after the final run.

## 8. Compliance Matrix

| ID | Requirement | Implemented | Integrated | Tested | Result |
|---:|---|:---:|:---:|:---:|:---:|
| 115 | Mandatory sacramental fields | YES | YES | YES | PASS |
| 116 | Date validation | YES | YES | YES | PASS |
| 117 | Duplicate detection | YES | YES | YES | PASS |
| 118 | Official registry numbers | YES | YES | YES | PASS |
| 119 | Registry uniqueness | YES | YES | YES | PASS |
| 120 | Approved request linkage | YES | YES | YES | PASS |
| 121 | Correction workflow | YES | YES | YES | PASS |
| 122 | Correction history | YES | YES | YES | PASS |
| 123 | Record locking | YES | YES | YES | PASS |
| 124 | Privileged locked editing | YES | YES | YES | PASS |
| 125 | Archive/restore | YES | YES | YES | PASS |
| 126 | CSV preview/import validation | YES | YES | YES | PASS |
| 127 | Import error report | YES | YES | YES | PASS |
| 128 | Certificate lifecycle | YES | YES | YES | PASS |
| 129 | Preview does not issue | YES | YES | YES | PASS |
| 130 | Certificate review | YES | YES | YES | PASS |
| 131 | Final issuance confirmation | YES | YES | YES | PASS |
| 132 | Server PDF generation | YES | YES | YES | PASS |
| 133 | Immutable snapshot | YES | YES | YES | PASS |
| 134 | SHA-256 hash | YES | YES | YES | PASS |
| 135 | QR verification | YES | YES | YES | PASS |
| 136 | Online verification | YES | YES | YES | PASS |
| 137 | Minimum public data | YES | YES | YES | PASS |
| 138 | Certificate revocation | YES | YES | YES | PASS |
| 139 | Reissue history | YES | YES | YES | PASS |
| 140 | Request linkage | YES | YES | YES | PASS |
| 141 | Download/release logging | YES | YES | YES | PASS |
| 142 | Structured notification types | YES | YES | YES | PASS |
| 143 | Entity references | YES | YES | YES | PASS |
| 144 | Action links | YES | YES | YES | PASS |
| 145 | Notification states | YES | YES | YES | PASS |
| 146 | Notification preferences | YES | YES | YES | PASS |
| 147 | Notification templates | YES | YES | YES | PASS |
| 148 | Delivery status | YES | YES | YES | PASS |
| 149 | Failed notification resend | YES | YES | YES | PASS |
| 150 | Announcement audiences | YES | YES | YES | PASS |
| 151 | Scheduled publication/expiration | YES | YES | YES | PASS |
| 152 | Draft/preview | YES | YES | YES | PASS |
| 153 | Attachment security | YES | YES | YES | PASS |
| 154 | Announcement UI states | YES | YES | YES | PASS |

## 9. Remaining Issues

- Seven pre-Phase-9 certificate rows have numbers/tokens but no historical PDF bytes or SHA-256 hash. Their exact original files cannot be truthfully reconstructed. They are preserved for audit, blocked from release by the new service, and shown as **Unverified** rather than valid by public verification. An administrator should revoke and reissue each one through the controlled workflow if an official replacement is required.
- Production scheduling still must invoke `database/run-announcement-lifecycle.php` and `database/run-notification-deliveries.php` through the chosen scheduler. Creating deployment/production scheduler jobs was intentionally outside this development-only phase.

## Recovery

Pre-migration dump: `C:\Users\MyPC\AppData\Local\Temp\TUGON_phase8_10_pre_migration_20260821.sql`  
SHA-256: `390A18DE12FACCEE7B097874B28CFBB80356FA110D7A4C17CA6F73DDB36C4EFE`
