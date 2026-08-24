# TUGON Phase 7 Reservation & Scheduling Completion Report

Date: 2026-08-21  
Authoritative timezone: `Asia/Manila` (`DATETIME` civil-time storage; PHP and MySQL sessions are fixed to UTC+08:00)

## 1. Existing reservation architecture

The previous implementation stored only `event_date` and `event_time`, checked an exact `(reservation_type,date,time)` match with a normal `SELECT`, and then performed a separate `INSERT`. It had no resources, occupied duration, blackouts, row locking, proposal workflow, or schedule history. The parishioner form lacked CSRF enforcement. Administrator code directly updated reservation rows, while separate helper functions independently created/cancelled calendar rows. Reservations were also displayed a second time through their linked unified request, allowing the two status records to diverge.

## 2. Problems found

- Exact-time comparison missed partial, contained, and setup/cleanup overlaps.
- Check-then-insert was vulnerable to concurrent double booking.
- Facilities, people, and equipment were hard-coded concepts rather than shared resources.
- Cancelled/rejected behavior and calendar cleanup were duplicated between controllers.
- Calendar sync assumed a one-hour event and could create inconsistent state.
- No blackouts, recurring unavailability, proposals, acceptance ownership check, rescheduling history, or idempotent reminders existed.
- Reservation creation bypassed Phase 5 `RequestService` and had no CSRF check.
- The generic request list exposed the linked request and reservation as separate workflow items.

## 3. New architecture

- `ReservationService` is the transaction boundary for creation, decisions, proposals, responses, request-state transitions, audit events, notifications, reminders, and calendar state.
- `ResourceAvailabilityService` owns timestamp parsing, resource validation/locking, occupied-window overlap, blackout evaluation, and validated alternative-slot suggestions.
- `CalendarService` performs idempotent reservation-source upserts and state-driven cancellation.
- `ReservationReminderService` claims due reminder rows transactionally and marks them sent in the same transaction as the in-app notification.
- `RequestService` now exposes transaction-aware create/transition operations so reservations reuse Phase 5 without nested transactions.
- Parishioner, administrator, and resource-management pages use these services; the generic request list excludes linked reservation requests.

## 4. Database changes

Migration: `database/canonical-migrations/007_reservation_scheduling.sql`

- Added `resources` and seeded Main Church, Parish Chapel, Parish Hall, and Sound System.
- Added `resource_unavailability`, `reservation_resources`, `schedule_proposals`, `schedule_proposal_resources`, `reservation_schedule_history`, and `reservation_notifications`.
- Added `reservations.request_id`, `start_at`, `end_at`, service/setup/cleanup durations, and timezone.
- Backfilled legacy reservation timestamps, linked every legacy reservation to a unified request, assigned Main Church, and created baseline history.
- Removed the weak exact-time unique key.
- Added foreign keys, scheduling/resource indexes, a unique reservation/request link, reminder idempotency key, and unique calendar source key.
- Pre-migration recovery dump: `C:\Users\MyPC\AppData\Local\Temp\TUGON_phase7_pre_migration_20260821.sql` (SHA-256 `4C52FB8BD7ED43F369F5CFA653DB0F57119FA9D2424FA18A37A0CB5AC70DDFBF`).

## 5. Scheduling rules

- All authoritative values are interpreted and displayed as Asia/Manila civil time.
- Service duration is 15–1440 minutes; setup/cleanup are 0–1440 minutes.
- Occupancy is `[start_at - setup, end_at + cleanup)`; exact adjacent boundaries are allowed.
- Every selected resource must exist, be active/available, and be row-locked before the conflict decision.
- An active reservation conflicts when existing occupied start is before proposed occupied end and existing occupied end is after proposed occupied start.
- Cancelled and rejected reservations never block resources.
- One-time and time-specific blackouts are supported. Recurrence supports `weekly:0` through `weekly:6` and `annual:MM-DD`.
- No parish-wide operating-hours policy was present in the source system, so booking does not invent one. Alternative suggestions use a bounded 07:00–21:00 search window and the authoritative availability engine.

## 6. State and proposal flow

Reservation submission creates `request(submitted)` plus `reservation(pending)`. Approval advances the unified request through `requirements_review` to `approved`, activates one calendar event, and queues reminders. Rejection/cancellation follows allowed state-machine transitions and cancels any linked event. Approved reservations may be cancelled but cannot be changed to rejected.

An administrator with `reservations.manage` creates a pending schedule proposal without overwriting the current schedule. The owner can accept or reject it. Acceptance locks resources and rechecks conflicts/blackouts, changes timestamps/resources, replaces obsolete unsent reminders, updates the existing calendar event, and records old/new history. Rejection remains stored and notifies the proposer. Expired proposals and non-owner responses are denied.

## 7. Security controls

- Parishioner routes require login plus own-request permissions; admin routes require `reservations.manage`.
- Every modifying form enforces the shared CSRF token.
- Ownership is derived from the authenticated session and revalidated during proposal response.
- Reservation type, timestamps, durations, resource IDs, status transitions, proposal expiration, and text lengths are server-validated.
- Resource rows are locked in deterministic ID order within the transaction, preventing same-resource concurrent creation races.
- Request/reservation/resource/history/calendar/reminder/audit changes use prepared statements and transactional rollback.
- Archived/unavailable resources cannot be selected or booked.

## 8. Testing results

Automated Phase 7 suites:

- `phase7_reservation_test.php`: 13 passed, 0 failed (intervals, setup/cleanup, adjacency, resources, cancelled/rejected, blackouts, recurring blackout, real two-connection row-lock contention).
- `phase7_resource_management_test.php`: 4 passed, 0 failed (create, edit, maintenance denial, archive).
- `phase7_service_integration_test.php`: 6 passed, 0 failed (unified atomic creation, idempotency, conflict denial, calendar idempotency, state machine, reminders).
- `phase7_proposal_reminder_test.php`: 14 passed, 0 failed (multi-resource, proposal/notification, IDOR denial, stale availability, accept/reject/expiry, history, calendar reschedule/cancel, reminder replacement and duplicate prevention).

Phase 1–5 regression suites also pass: 33 Phase 1 checks, Phase 2 authentication checks, Phase 3 security checks, Phase 4 database checks, and Phase 5 workflow checks all report zero failures. All changed PHP files pass `php -l`.

## 9. Phase 7 compliance matrix

| ID | Requirement | Implemented | Integrated | Tested | Result |
|---|---|---|---|---|---|
| 102 | Resources table/system | YES | YES | YES | PASS |
| 103 | Start/end timestamps | YES | YES | YES | PASS |
| 104 | Duration/setup/cleanup | YES | YES | YES | PASS |
| 105 | Blackout schedules | YES | YES | YES | PASS |
| 106 | Shared-resource conflicts | YES | YES | YES | PASS |
| 107 | Ignore cancelled/rejected | YES | YES | YES | PASS |
| 108 | Transaction-safe booking | YES | YES | YES | PASS |
| 109 | Admin schedule proposal | YES | YES | YES | PASS |
| 110 | Parishioner proposal response | YES | YES | YES | PASS |
| 111 | Rescheduling history | YES | YES | YES | PASS |
| 112 | Calendar synchronization | YES | YES | YES | PASS |
| 113 | Calendar cancellation | YES | YES | YES | PASS |
| 114 | Reminders/notifications | YES | YES | YES | PASS |

## 10. Remaining operational issues

- Production/development task scheduling must invoke `php database/run-reservation-reminders.php` at a suitable interval (for example every five minutes). Deployment or scheduler configuration was intentionally not started.
- Reminder offsets default to `1440,120` minutes and can be configured with `RESERVATION_REMINDER_MINUTES` as a comma-separated list.
- Email/SMS delivery remains governed by the existing notification-channel configuration; Phase 7 guarantees in-app proposal/status/reminder notifications.
- Existing legacy reservations retain Main Church as their migration-time resource assignment. Staff should review those preserved assignments rather than the migration guessing additional priests/staff/equipment.
