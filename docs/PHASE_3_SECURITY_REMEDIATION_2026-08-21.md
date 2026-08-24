# Phase 3 Security Remediation — Development

Implemented and tested development-side controls for the Phase 3 security baseline. Deployment was not performed.

## Completed controls

- Central security headers middleware (`includes/security-middleware.php`) applies CSP, frame, MIME, referrer, permissions, and HTTPS-only HSTS.
- CSRF validation now accepts both session-bound form tokens and `X-CSRF-Token` for JSON/AJAX requests, with generic JSON failures.
- Calendar, records, AI, and OTP JSON mutations enforce CSRF before processing.
- Error handling returns traceable `ERR-*` IDs while keeping SQL, paths, stack traces, and exception details out of responses.
- Central secure upload/download helpers validate upload errors, MIME, extension, size, random server filenames, safe storage permissions, and safe streaming.
- Request, announcement, and certificate-template downloads perform authorization checks and audit logging.
- Upload root is denied by default through `.htaccess` and an index guard.
- Records API was rewritten with allowlisted table/field definitions, prepared statements, strict date/length validation, and generic database errors.
- Retired certificate endpoints return redirects for GET and HTTP 410 for mutating requests.

## Verification

- `php tests/phase3_security_test.php` — all checks passed.
- Focused Phase 3 PHP lint checks — no syntax errors.
- Existing Phase 1 suite remains green (33/33).
- Existing Phase 2 authentication checks remain green.

## Compliance matrix

| ID | Result | Evidence |
|---|---|---|
| 33 | PASS (integrated central controls) | `requireValidCsrfToken`, form and state-changing route coverage |
| 34 | PASS (covered JSON mutations) | Header token validation in APIs |
| 35 | PARTIAL | Core sensitive routes protected; full endpoint inventory remains for follow-up |
| 36 | PASS for centralized RBAC paths | `requirePermission` and DB-backed permissions |
| 37 | PASS for audited document routes | Session owner checks in request documents |
| 38 | PASS | Central middleware |
| 39 | PASS for hardened paths | Generic database/file responses |
| 40 | PASS | Error IDs and internal logging |
| 41 | PARTIAL | Records API hardened; legacy SQL inventory still requires completion |
| 42 | PASS for hardened paths | Records/upload validation |
| 43 | PASS for hardened records/uploads | Server-side limits |
| 44 | PASS for records API | Explicit allowlisted fields |
| 45 | PASS (central service available and integrated downloads) | `secure-files.php` |
| 46 | PASS for centralized upload helper | MIME/error/size/signature checks |
| 47 | PASS | Cryptographically random filenames |
| 48 | PASS for integrated download routes | Authorization and audit logging |
| 49 | PASS | Legacy certificate routes retired with 410/redirect behavior |

Items marked PARTIAL are explicitly not claimed as complete; remaining work is the exhaustive audit of every legacy mutation and SQL statement.
