# Phase 4 Database Integrity Remediation — Development

## Audit and repair summary

The live development schema was inspected before modification. Targeted orphan checks for documents, payments, announcements, notification preferences, reservations, sacramental request links, and certificate template references returned zero rows.

Applied canonical migrations:

- `004_integrity_indexes_and_references.sql`
  - Added verified request/document/payment/user relationships.
  - Added reservation, notification, preference, announcement-recipient, sacramental-request, certificate, and layout-user foreign keys.
  - Changed historical reservation/notification/user relationships from cascading deletes to `RESTRICT`.
  - Used `SET NULL` for optional historical actors/templates.
  - Added composite workload indexes and recipient/preference uniqueness.
- `005_monetary_and_data_quality.sql`
  - Added `confirmation_records.stipend_amount DECIMAL(10,2)`.
  - Preserved malformed legacy stipend text in the original columns rather than silently deleting it.
  - Added confirmation registry/date indexes.

Application changes:

- Request references now use `TUGON-YYYY-XXXXXXXX` cryptographic identifiers, with the existing unique database constraint as final collision protection.
- Certificate numbers now use collision-resistant random suffixes instead of `MAX()+1`.
- Certificate issuance inserts execute transactionally.
- Synthetic development seed data is available at `database/seeds/development_seed.sql` and is not applied automatically.

Recovery dump before Phase 4 migration:

- `C:\Users\MyPC\AppData\Local\Temp\TUGON_phase4_pre_migration_20260821.sql`
- SHA-256: `49EBF977B573C23F4189802B0B342D687DC1538F5BB4A8007F08B309F17A41C2`

## Verification

- `php tests/phase4_database_test.php` — 0 failed checks.
- `php tests/phase3_security_test.php` — 0 failed checks.
- `php tests/phase2_auth_test.php` — passed.
- `php tests/phase1_stabilization_test.php` — 33 passed, 0 failed.
- Migration ledger reports migrations `000` through `005` applied and checksum-valid.

## Compliance matrix

| ID | Result | Notes |
|---|---|---|
| 50 | PASS | Requests already had FK; integrity verified |
| 51 | PASS | Documents now reference requests and uploaders |
| 52 | PASS | Payments reference requests/users/documents with historical protection |
| 53 | PASS | Reservations reference users with RESTRICT |
| 54 | PASS | Notifications reference users with RESTRICT |
| 55 | PASS | Preferences and recipients have FKs and uniqueness |
| 56 | PASS | Sacramental request links added with SET NULL |
| 57 | PASS | Certificate template/issuer and layout actors linked |
| 58 | PASS | Delete behaviors explicitly selected in migration |
| 59 | PASS | Official child rows prevent physical user deletion |
| 60 | PARTIAL | Existing soft-delete conventions remain mixed; no destructive rewrite performed |
| 61 | PASS | Request workload indexes added |
| 62 | PASS | Reservation conflict/user indexes added |
| 63 | PASS | Notification composite indexes added |
| 64 | PARTIAL | Existing registry fields audited/indexed; business-specific duplicate resolution needs parish confirmation |
| 65 | PARTIAL | Payment and new stipend storage are DECIMAL; legacy stipend text is preserved |
| 66 | PARTIAL | Legacy age columns remain for compatibility; calculation migration needs workflow-specific rollout |
| 67 | PARTIAL | Participant names remain legacy denormalized fields pending domain confirmation |
| 68 | PARTIAL | Core password/OTP/certificate operations transactional; all multi-step workflows need further inventory |
| 69 | PARTIAL | Central upload cleanup exists, but every workflow needs failure-injection coverage |
| 70 | PASS | Collision-resistant request references and unique constraint |
| 71 | PASS | Transactional certificate issuance and collision-resistant numbering |
| 72 | PASS | Synthetic-only development seed script created |

Items marked PARTIAL are deliberately not claimed as complete where schema/business decisions require parish-owner confirmation or broader workflow migration.
