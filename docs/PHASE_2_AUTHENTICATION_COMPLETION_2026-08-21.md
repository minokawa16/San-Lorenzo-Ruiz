# Phase 2 Authentication and RBAC Completion

Development-side authentication work for specification items 13–32 is complete. Deployment and production cutover were not performed.

## Implemented

- Password login now uses verified server-side email/mobile identifiers and generic failure responses.
- Administrators always require OTP MFA; parishioners follow `login_otp_enabled`.
- OTPs are hashed, expiring, rate-limited, attempt-limited, resend-limited, and bound to a server-side transaction and session.
- OTP verification no longer trusts browser-supplied user or destination values.
- Password recovery uses an opaque transaction and identical responses for existing and non-existing accounts.
- Roles, permissions, user-role assignments, login attempts, OTP transactions, password history, status history, and registration reviews are database-backed.
- Ambiguous legacy mobile numbers are deliberately left unassigned instead of guessing an owner.
- Parishioner archival now requires CSRF and records status history through the centralized transition function.
- Legacy role-manager permission checks now resolve through `roles`, `user_roles`, and `role_permissions`.

## Recovery point

- Dump: `C:\Users\MyPC\AppData\Local\Temp\TUGON_phase2_recovery_20260821.sql`
- SHA-256: `35989A66458D63035166B9E8EF24971482C97ED7CD735CC5B149A23457018AF0`

## Verification

- `php tests/phase1_stabilization_test.php` — 33 passed, 0 failed.
- `php tests/phase2_auth_test.php` — ambiguous-contact, mandatory-admin-MFA, and administrator-permission checks passed.
- `php database/migrate.php status` — all canonical migrations applied and checksum-valid.
- Focused Phase 2 PHP files pass `php -l`.
