# Phase 5 Unified Request System — Development

Implemented a shared request workflow foundation and integrated the administrative status path.

## Architecture

- `services/RequestService.php` owns request creation, idempotency handling, transactional persistence, and state transitions.
- `services/RequestStateMachine.php` is the single transition map and next-action calculator.
- `api/requests.php` provides authenticated, ownership-aware request details, public messages, internal notes, and transitions.
- `006_unified_request_workflow.sql` adds workflow metadata, history, messages, notes, assignments, and idempotency tables.
- `admin/process-request.php` now routes approve/reject/request-more/complete actions through the state machine. Legacy `pending` approval is explicitly walked through `requirements_review` before `approved`.
- Certificate request creation now uses the shared service and a form-bound idempotency key.

## State machine

States: `draft`, `submitted`, `requirements_review`, `needs_information`, `payment_required`, `payment_review`, `approved`, `scheduled`, `processing`, `ready_for_release`, `completed`, `rejected`, `cancelled`.

Terminal states are `completed`, `rejected`, and `cancelled`; skipped transitions are rejected server-side.

## Verification

- `php tests/phase5_request_test.php` — 0 failures.
- Phase 4 database tests — 0 failures.
- Phase 3 security tests — 0 failures.
- Phase 2 authentication tests — passed.
- Phase 1 stabilization tests — 33 passed, 0 failed.
- Focused Phase 5 PHP lint checks — no syntax errors.

## Compliance matrix

| ID | Result | Notes |
|---|---|---|
| 73 | PARTIAL | Shared service exists; certificate/admin paths integrated, blessing/service/reservation forms remain legacy callers |
| 74 | PASS | Central state machine enforced by service/API/admin path |
| 75 | PASS | Required stable states added to schema |
| 76 | PASS | Central transition map |
| 77 | PASS | Skipped transitions denied |
| 78 | PASS | Server-generated next-action calculation |
| 79 | PARTIAL | History table/API exists; full UI timeline integration remains |
| 80 | PARTIAL | Assignment schema exists; staff assignment UI/action remains |
| 81 | PARTIAL | Priority/due-date schema exists; management UI remains |
| 82 | PASS | Internal notes are separate and excluded from parishioner API responses |
| 83 | PASS | Public messages use visibility filtering |
| 84 | PASS | Two-way public message API with ownership checks |
| 85 | PASS | Status history records previous/new state, actor, reason, timestamp |
| 86 | PASS | Rejection/return/cancellation reasons validated centrally |
| 87 | PASS | Owner withdrawal is limited to eligible states through the API state machine |
| 88 | PASS for shared creation path | Unique user/operation/idempotency key storage and replay response |

Items marked PARTIAL are intentionally not overstated; the remaining work is wiring every existing legacy form and completing the request-detail UI around the new shared API.
