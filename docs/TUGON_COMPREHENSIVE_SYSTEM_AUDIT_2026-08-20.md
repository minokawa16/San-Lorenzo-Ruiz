# TUGON Comprehensive System Audit

**System:** TUGON: An AI-Powered Web-Based Parish Request and Sacramental Records Management System  
**Audit date:** 20 August 2026  
**Audit type:** Static code review, live local-schema inspection, workflow review, and build/test verification  
**Scope:** PHP application, MySQL schema, React/Vite AI client, Node/Gemini gateway, Ollama integration, uploads, authentication, admin and parishioner workflows, reports, audit logs, deployment configuration, responsive UI, and accessibility.

## Executive assessment

TUGON has a broad and useful feature set, recognizable parish workflows, ownership checks on important user-facing request pages, prepared statements in many modern paths, encrypted identity captures, certificate verification codes, responsive navigation, notification channels, audit logging, and a working production build. However, it is **not ready for real parish deployment**.

The decisive blockers are systemic rather than cosmetic:

1. State-changing routes are inconsistently protected against CSRF.
2. Login throttling is configured but not enforced; OTP endpoints are forgeable/resendable without CSRF and expose account existence.
3. Publicly reachable diagnostic/install scripts contain known admin credentials and database setup capability.
4. The live schema lacks many foreign keys and differs materially from the canonical SQL files.
5. Schema changes are executed during normal page requests and the migration runner has no migration ledger, transaction boundary, or reliable failure status.
6. The Admin AI UI cannot work with the API as implemented.
7. Sensitive records, identity images, uploaded evidence, backups, and AI inquiry content do not have a documented/enforced retention and privacy lifecycle.
8. Automated test coverage is effectively limited to one 30-case conversational-intent script.

**Overall system score: 4.5/10**  
**Current system status: Needs Major Improvement**  
**Actual parish deployment:** Not approved  
**Capstone presentation:** Presentable only as a controlled prototype after the demo blockers and known-credential routes are fixed; it must not be represented as production-ready.

## Method and evidence

- Inspected approximately 257 first-party PHP, JavaScript/JSX, SQL, and CSS files.
- Queried the local `parish_management_system` information schema, indexes, constraints, row counts, and orphan relationships.
- Ran PHP syntax lint on every non-vendor PHP file: all passed.
- Ran `tests/conversational_intent_test.php`: 30 cases passed.
- Ran the production Vite build: passed; generated JS was about 196 KB (62 KB gzip).
- Found no PHPUnit configuration, backend integration test suite, browser end-to-end suite, accessibility test suite, or security regression suite.
- No destructive testing, credential attacks, external penetration testing, production traffic testing, or visual browser matrix was performed. Findings about runtime behavior are therefore conservative and should be verified in staging.

## Detailed findings register

Each finding includes the requested lapse, impact, severity, solution, and priority.

### Security, privacy, and access control

#### S-01 — CSRF protection is absent from many state-changing routes

**Problem/Lapse:** CSRF helpers exist, but many mutations do not call them. Examples include request/reservation status changes and archive actions (`admin/manage-requests.php`), record CRUD pages, announcements, user administration, registration approval, system settings and recovery, user reservations and notification deletion, calendar mutations, records API mutations, OTP resend/verification, and the legacy certificate endpoints.  
**Why It Matters:** A logged-in administrator or parishioner can be tricked into changing records, approving requests, restoring backups, uploading files, or deleting data from another site. Settings/recovery CSRF can become catastrophic.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Enforce a single CSRF middleware/helper on every POST/PUT/PATCH/DELETE route; accept a session-bound token via form field or `X-CSRF-Token`; reject with 403; rotate at authentication boundaries; add regression tests that enumerate every mutating route.  
**Priority:** Must Fix

#### S-02 — Public installer/debug scripts expose known admin credentials

**Problem/Lapse:** `setup-database.php`, `database/install.php`, `debug-login.php`, `fix-password.php`, `verify-login.php`, and related utilities are tracked and executable in a normal XAMPP checkout. The installer creates or advertises `admin@parish.com` with `admin123`. The Docker ignore file reduces one deployment path, but does not protect XAMPP, shared hosting, an incorrectly built image, or source distribution.  
**Why It Matters:** An attacker can learn or reset privileged credentials, initialize/alter the database, and obtain diagnostic information. This is a direct administrative-compromise risk.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Remove these scripts from the web root and repository release artifact. Replace web installers with authenticated CLI migrations. Bootstrap the first admin through a one-time CLI command using a randomly generated password that must be changed on first login. Rotate every existing default credential.  
**Priority:** Must Fix

#### S-03 — Login brute-force protection is configured but not implemented

**Problem/Lapse:** `MAX_LOGIN_ATTEMPTS`, lockout duration, and attempt window are defined, and a `login_attempts` table appears in an optional improvement script, but `auth/login.php` does not consult or update it.  
**Why It Matters:** Password guessing and credential stuffing can be performed without account/IP throttling. Admin accounts are especially exposed.  
**Severity:** 🟠 High  
**Recommended Improvement:** Add combined identifier/IP rate limiting with exponential delay, short lockout, audit events, generic errors, and monitoring. Use a shared store rather than only a PHP session so clearing cookies does not bypass it.  
**Priority:** Must Fix

#### S-04 — Login OTP setting is not enforced

**Problem/Lapse:** `users.login_otp_enabled` exists, but successful password verification in `auth/login.php` immediately creates the authenticated session. The OTP login branch is not invoked.  
**Why It Matters:** The UI/database can claim stronger protection that does not exist, creating a false sense of security for administrators and parishioners.  
**Severity:** 🟠 High  
**Recommended Improvement:** After password verification, create only a short-lived pre-auth session; send OTP to the verified contact; complete `loginUser()` only after OTP success. Require MFA for all administrators and recovery/settings operations.  
**Priority:** Must Fix

#### S-05 — OTP endpoints lack CSRF, binding, resend throttling, and enumeration resistance

**Problem/Lapse:** `auth/verify-otp.php` trusts `user_id`, method, and contact from GET/POST; `api/email-otp.php` is public; neither has consistent CSRF protection. Responses reveal whether an account exists. Three verification attempts are limited per OTP, but sending/resending is not robustly rate-limited by account, IP, and time. Registration verification can be created for a supplied user ID/contact pair without first proving that contact belongs to that user.  
**Why It Matters:** Attackers can spam email/SMS, enumerate accounts, consume messaging quota, manipulate verification state, and abuse recovery workflows.  
**Severity:** 🟠 High  
**Recommended Improvement:** Store a random pre-auth transaction ID server-side that binds user, purpose, verified destination, expiry, attempts, and resend cooldown. Never accept user/contact identity from the browser after initiation. Add CSRF, generic responses, per-IP/account limits, single-use semantics, and audit alerts.  
**Priority:** Must Fix

#### S-06 — Security-header configuration is dead code

**Problem/Lapse:** `config/security.php` defines CSP, HSTS, frame, referrer, and permissions headers, but application templates/routes do not iterate and send them. Numerous pages load third-party CDN assets without Subresource Integrity. CSP also permits `unsafe-inline`.  
**Why It Matters:** Browser defenses against XSS, clickjacking, content injection, and downgrade attacks are absent despite appearing configured. CDN compromise or availability loss affects core UI and registration.  
**Severity:** 🟠 High  
**Recommended Improvement:** Apply headers in one bootstrap before output. Use HTTPS-only HSTS in production, nonce/hash-based CSP without `unsafe-inline`, SRI/crossorigin on pinned CDN assets, or self-host dependencies. Test headers in CI and staging.  
**Priority:** Must Fix

#### S-07 — Session handling is inconsistent

**Problem/Lapse:** Some pages use `includes/session.php`; others call `session_start()` directly and therefore bypass centralized timeout/cookie behavior. `auth/verify-otp.php` writes login session fields directly instead of using `loginUser()`, so it does not visibly regenerate the session identifier. Secure cookies depend on `APP_ENV=production`, not the actual HTTPS request.  
**Why It Matters:** Authentication paths can have different fixation, timeout, and cookie guarantees. A deployment misconfiguration can silently produce non-secure cookies.  
**Severity:** 🟠 High  
**Recommended Improvement:** Make one bootstrap mandatory for every route, set strict-mode cookies before session start, derive `Secure` from trusted proxy/HTTPS plus production enforcement, regenerate on all privilege changes, and reject inconsistent session state.  
**Priority:** Must Fix

#### S-08 — Legacy certificate subsystem is unsafe and incompatible

**Problem/Lapse:** `certs/*` references legacy uppercase tables such as `Certificate_Requests`, `Requirement_Files`, and `Audit_Logs`, which are absent from the live schema. Several routes lack CSRF, safe extension canonicalization, modern ownership checks, or the hardened upload helper.  
**Why It Matters:** Routes are broken at best; if legacy tables are later restored, they create a second, less-secure workflow that can permit unauthorized file replacement or inconsistent records.  
**Severity:** 🟠 High  
**Recommended Improvement:** Remove/410 all legacy routes or migrate them to `requests`, `request_documents`, and `request_payments`. Keep one authorized download controller and one validated upload service.  
**Priority:** Must Fix

#### S-09 — File authorization has a weak legacy path check

**Problem/Lapse:** `secure_file.php` uses a prefix test on `realpath` without a directory-separator boundary and checks `$_SESSION['is_admin']`, while normal login stores `role`. It queries legacy tables. Modern `request-document.php` is materially better but repeats the same prefix style.  
**Why It Matters:** Legacy files can become inaccessible to legitimate admins or improperly matched if sibling directory names share the same prefix; split authorization logic is difficult to assure.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Delete the legacy route, compare canonical paths using `base + DIRECTORY_SEPARATOR`, use centralized permissions, store uploads outside the document root/object storage, force safe dispositions, and log downloads of sensitive documents.  
**Priority:** Must Fix

#### S-10 — Sensitive data lifecycle is undefined

**Problem/Lapse:** The system stores identity numbers, face images, front/back IDs, contact data, payment receipts, sacramental records, full AI questions, logs, and backups. Encryption helpers exist for ID values/captures, but there is no enforced retention schedule, deletion/anonymization process, legal-basis/consent record, export process, access review, or breach-response workflow.  
**Why It Matters:** Parish records are highly sensitive and may be retained for very different periods. Indefinite storage increases privacy, breach, and regulatory exposure. Face/ID data is especially high risk.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Complete a privacy impact assessment; classify fields; document purpose and retention per data class; minimize biometric/ID retention; encrypt backups; implement subject-access/correction workflows; restrict and audit every sensitive view/download; define incident response and secure disposal. Obtain local legal/privacy review before deployment.  
**Priority:** Must Fix

#### S-11 — Authorization model and database role model disagree

**Problem/Lapse:** PHP defines `parish_staff`, `records_clerk`, `finance_staff`, and coordinator permissions, but `users.role` is `ENUM('user','admin')`. Many record pages still compare the session role directly to `admin`, bypassing capability-based permissions.  
**Why It Matters:** Least privilege cannot be deployed consistently. Adding staff by changing the enum would still produce unpredictable denial or privilege behavior.  
**Severity:** 🟠 High  
**Recommended Improvement:** Normalize roles and permissions in database tables, map users to roles, enforce `requirePermission()` on every route/action, and test each role against an explicit authorization matrix.  
**Priority:** Must Fix

#### S-12 — Technical database errors reach users

**Problem/Lapse:** Many pages append `$conn->error`/statement errors to browser messages; connection setup dies with infrastructure details.  
**Why It Matters:** Schema names, SQL details, hosts, and operational state can leak and confuse parish users.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Return stable user-facing error IDs and generic messages; log structured technical detail server-side with redaction; show stack/SQL details only in an authenticated development environment.  
**Priority:** Should Fix

### Database, integrity, architecture, and reliability

#### D-01 — Runtime schema lacks required foreign keys

**Problem/Lapse:** Live inspection found no foreign keys for `request_documents`, `request_payments`, `notification_preferences`, `announcement_recipients`, sacramental record request links, certificate issuances/templates/layouts, chatbot knowledge editors, verification records, and maintenance/recovery actors. The current sampled data had zero orphans, but the database does not prevent them.  
**Why It Matters:** Deletes, partial failures, manual SQL, and future code can silently create orphan documents, payments, identity links, and official certificates.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Clean existing data, add indexed FKs with deliberate `RESTRICT`, `SET NULL`, or `CASCADE` rules, and test deletion scenarios. Official sacramental/certificate history should generally use `RESTRICT` or immutable snapshots, not cascade deletion.  
**Priority:** Must Fix

#### D-02 — Installation and migration sources conflict

**Problem/Lapse:** There are multiple installers, `setup.sql`, `schema_improvements.sql`, standalone migrations, page-level `ensure*Schema` functions, and runtime `ALTER TABLE` statements. The live schema differs from `setup.sql`; e.g., several expected FKs are missing. A typo targets `first_communion_rercords`.  
**Why It Matters:** Two installations can produce different systems. Bugs reproduce inconsistently, rollback is unclear, and production requests need DDL privileges.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Create one versioned, idempotent migration chain with a `schema_migrations` ledger and checksums. Produce a fresh-database integration test. Remove DDL from HTTP requests and delete alternate installers.  
**Priority:** Must Fix

#### D-03 — Migration runner reports success after SQL failures

**Problem/Lapse:** `database/run-migrations.php` splits SQL with a regex, continues after errors, records each file as success regardless of warnings, has no migration ledger, and has no per-migration transaction/rollback. Localhost is automatically trusted.  
**Why It Matters:** A partially applied schema can be labeled successful and later corrupt workflows. Proxy/container networking can also make localhost trust unsafe.  
**Severity:** 🟠 High  
**Recommended Improvement:** Replace it with a CLI-only migration framework; fail fast; record version/checksum/timestamp; use transactions where supported; add preflight backups and explicit rollback/runbook.  
**Priority:** Must Fix

#### D-04 — Multi-step workflows are usually non-transactional

**Problem/Lapse:** Registration, request creation plus document upload, status update plus notification/calendar sync, announcement creation plus recipient queue, and file/database replacement generally perform multiple writes without atomic transactions or compensation. Only a few isolated components use transactions.  
**Why It Matters:** A file can exist without a DB row, a request can update without its notification/calendar, or an account can remain after failed verification setup.  
**Severity:** 🟠 High  
**Recommended Improvement:** Define transaction boundaries for DB changes and compensating cleanup for filesystem/external messages. Use an outbox table for email/SMS/notifications and idempotent workers.  
**Priority:** Must Fix

#### D-05 — Sacramental records are insufficiently normalized and constrained

**Problem/Lapse:** Parents, godparents, sponsors, witnesses, addresses, ages, and monetary components are stored as free-form strings. Registry/book/page/entry numbers are nullable and not uniquely constrained. Age and stipend are strings rather than derived/numeric values.  
**Why It Matters:** Duplicate official records, inconsistent spelling, inaccurate reports, and unreliable certificate generation become likely. Searching people across sacraments is weak.  
**Severity:** 🟠 High  
**Recommended Improvement:** Model persons/participants and sacramental events separately where useful; derive age from birth/event dates; use DECIMAL for money; enforce a parish-defined unique registry key such as sacrament + book + page + entry; retain an immutable source snapshot for issued certificates.  
**Priority:** Should Fix

#### D-06 — User/request deletion rules can erase history

**Problem/Lapse:** Core `requests.user_id` uses `ON DELETE CASCADE`, and documents cascade from requests in `setup.sql`, while official record links use `SET NULL`. Soft deletion is added inconsistently at runtime.  
**Why It Matters:** Deleting a user can destroy request and evidence history needed for audit, disputes, and parish operations.  
**Severity:** 🟠 High  
**Recommended Improvement:** Prohibit hard deletion of operational users/requests; use `deleted_at`, reason, actor, and retention status; anonymize only after approved retention; use `RESTRICT` for official chains.  
**Priority:** Must Fix

#### D-07 — Reference and certificate number generation is race-prone

**Problem/Lapse:** Request references use date plus `mt_rand`; certificate numbering reads the latest number and increments before insert. Uniqueness catches collisions but the workflow does not reliably retry.  
**Why It Matters:** Concurrent submissions/issuances can fail unpredictably and may interrupt front-desk work.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Use database sequences/counter rows locked in a transaction, or UUID/ULID internal identifiers plus a separately allocated human number. Retry on unique conflicts.  
**Priority:** Should Fix

#### D-08 — Character set is `utf8`, not `utf8mb4`

**Problem/Lapse:** The connection sets MySQL `utf8`, which cannot represent the full Unicode range.  
**Why It Matters:** Some names, symbols, and message content can fail or be corrupted.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Convert database/tables/columns to `utf8mb4` with a documented collation and call `set_charset('utf8mb4')`.  
**Priority:** Should Fix

#### D-09 — Database bootstrapping occurs inside ordinary pages

**Problem/Lapse:** Pages repeatedly execute `SHOW TABLES`, `SHOW COLUMNS`, `CREATE TABLE`, `ALTER TABLE`, data updates, and knowledge seeding during requests.  
**Why It Matters:** It adds latency, creates metadata locks, requires excessive production DB privileges, makes requests nondeterministic, and can fail under concurrency.  
**Severity:** 🟠 High  
**Recommended Improvement:** Run schema/data migrations only in deployment jobs. Give the web DB account DML-only privileges. Cache stable configuration instead of introspecting schema per request.  
**Priority:** Must Fix

#### D-10 — Architecture is highly duplicated and tightly coupled

**Problem/Lapse:** Large page controllers mix authorization, DDL, SQL, uploads, email/SMS, business rules, and extensive inline HTML/CSS/JS. There are duplicate dashboards, duplicate auth implementations, legacy certificate modules, two AI frontends, several CSS systems, and two audit table conventions.  
**Why It Matters:** Fixes are easily applied to one path but missed in another—the CSRF and role discrepancies demonstrate this already. Maintenance and onboarding costs are high.  
**Severity:** 🟠 High  
**Recommended Improvement:** Introduce a small front controller/router, middleware, service/repository layers, reusable view components, one design system, one audit schema, and remove deprecated routes after redirects/migration.  
**Priority:** Should Fix

### Workflow, functionality, AI, UI/UX, accessibility, and performance

#### W-01 — Admin AI Assistant is broken by contract mismatch

**Problem/Lapse:** `admin/ai-assistant.php` calls `api/ai-assistant.php` without the required CSRF header. The API also explicitly returns 403 for every logged-in non-parishioner, while later API functions contain admin analytics/search branches.  
**Why It Matters:** A named headline feature fails for administrators and undermines both usability and the capstone demonstration.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Define explicit user/admin API authorization; issue CSRF tokens to both clients; send the header; add API contract tests for chat/search/analytics for each role. Ensure admin prompts never reveal data beyond the admin's permission scope.  
**Priority:** Must Fix

#### W-02 — Guest AI compatibility endpoint is contradictory and potentially abusable

**Problem/Lapse:** `api/api/chat.php` enables guest mode, but the central endpoint still requires a session CSRF token. The route appears unused. If made usable, its session-only rate limit is trivial to bypass and it can consume Gemini/Ollama resources.  
**Why It Matters:** It is either dead code or an unplanned public cost/abuse surface.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Remove the route, or specify a real public-chat threat model with IP/device rate limits, CAPTCHA/abuse controls, minimal non-personal knowledge, no record search, and strict budget limits.  
**Priority:** Should Fix

#### W-03 — AI contains hard-coded parish requirements

**Problem/Lapse:** Baptism, marriage, confirmation and other requirements are embedded directly in PHP in addition to the administrator-managed knowledge base. This creates two sources of truth.  
**Why It Matters:** Parish policies change. The AI can confidently state outdated requirements despite the requirement not to invent official policy.  
**Severity:** 🟠 High  
**Recommended Improvement:** Remove policy facts from code. Retrieve only approved, effective-dated knowledge records with source, reviewer, version and expiry. Require a minimum retrieval confidence; otherwise answer that the parish office must confirm. Show citations/source dates in the UI.  
**Priority:** Must Fix

#### W-04 — AI RAG and hallucination controls are heuristic, not assured

**Problem/Lapse:** Retrieval uses substring/keyword scoring over at most 100 rows; recent client-supplied conversation is trusted as context; there is no evaluation corpus for factuality, prompt injection, privacy leakage, or bilingual policy consistency. Inquiry questions and answer previews are logged.  
**Why It Matters:** Similar topics can retrieve the wrong policy, model output can drift beyond sources, and sensitive user text may be retained unnecessarily.  
**Severity:** 🟠 High  
**Recommended Improvement:** Use structured/effective-dated policy documents, permission-filtered retrieval, grounded answer templates, citation validation, prompt-injection tests, PII redaction, configurable retention, and a bilingual gold evaluation set. The model should never perform mutations.  
**Priority:** Must Fix

#### W-05 — Parishioner request workflow is fragmented

**Problem/Lapse:** Certificate, blessing, sacramental service, and reservation requests use separate forms and partially different state rules. The current generic status enum cannot express `needs_information`, requirements under review, payment due/under review, scheduled, ready for release, released, or withdrawn. There is no structured two-way request conversation.  
**Why It Matters:** Users cannot clearly respond to missing requirements or understand the next action; administrators use free-text remarks to compensate.  
**Severity:** 🟠 High  
**Recommended Improvement:** Create a shared request workflow/state machine with allowed transitions, timestamps, assignee, due date, structured requirement checklist, comment/message thread, resubmission, and explicit user/admin next actions.  
**Priority:** Must Fix

#### W-06 — Reservation model is too rigid and conflict rules are unreliable

**Problem/Lapse:** A unique key on `(reservation_type,event_date,event_time)` permits different types at the same time even if they use the same church/minister; nullable time allows duplicate null-time rows; there are no duration/resource/capacity/blackout fields. The user conflict check treats cancelled records as conflicts and has a race between check and insert.  
**Why It Matters:** Double-booking, false conflicts, and scheduling disputes can occur.  
**Severity:** 🟠 High  
**Recommended Improvement:** Model resources, start/end timestamps, capacity, setup/cleanup buffers, blackout periods, and approval holds. Detect overlap transactionally and enforce it with locking/application constraints. Exclude rejected/cancelled reservations.  
**Priority:** Must Fix

#### W-07 — Certificate issuance and verification are incomplete

**Problem/Lapse:** Issuance is recorded when the preview is opened, not necessarily when an authorized final artifact is signed/released. Verification only retrieves detailed source records for baptism, communion, and confirmation—not marriage or funeral. There is no digital signature/hash of the final PDF, revocation reason/history, or duplicate/reissue workflow.  
**Why It Matters:** A preview can be mistaken for issuance; altered PDFs cannot be cryptographically detected; valid marriage/funeral certificates show incomplete verification.  
**Severity:** 🟠 High  
**Recommended Improvement:** Separate draft/render/approve/issue/release/revoke states. Store a hash and immutable snapshot of the issued PDF, sign server-side, include QR verification, support all certificate types, and retain reissue/revocation history.  
**Priority:** Must Fix

#### W-08 — Notifications are text-classified and not reliably actionable

**Problem/Lapse:** Notification category and destination are inferred from words in title/message; references are parsed from prose. Delivery is mixed into page requests in places. There is no durable retry/dead-letter worker or per-notification entity link.  
**Why It Matters:** Users can be routed to the wrong page, message delivery can slow/fail a transaction, and failures are difficult to recover.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Store `type`, `entity_type`, `entity_id`, `action_url`, template version, locale, channel status, attempts and next retry. Use an outbox/worker and delivery dashboard.  
**Priority:** Should Fix

#### W-09 — Registration is high-friction and excludes legitimate users

**Problem/Lapse:** Registration requires live camera, front/back ID, OCR, face comparison, age 13+, a Gmail-only address for email mode, and fixed placeholder chapel districts. Browser/camera/CDN failure can block enrollment. The client can submit `face_match_status`; server-side verification is not authoritative.  
**Why It Matters:** Elderly parishioners, users without Gmail/camera/data, and accessibility-tool users may be unable to register. Biometric collection is disproportionate unless formally justified.  
**Severity:** 🟠 High  
**Recommended Improvement:** Offer assisted/in-person registration and accessible document upload, accept valid non-Gmail email, configure real chapel data, perform authoritative server-side verification, minimize biometric use, and add consent/retention messaging.  
**Priority:** Must Fix

#### W-10 — Mobile functionality is mostly discoverable but not fully equivalent

**Problem/Lapse:** Responsive CSS and mobile navigation are extensive, but the bottom nav exposes only four destinations, dashboard quick access intentionally truncates items, and several admin record pages remain standalone layouts with dense tables/modals. Feature reachability depends on secondary menus rather than a consistent mobile information architecture.  
**Why It Matters:** Mobile users can miss reservations, schedules, announcements, or AI; admin table actions are harder to execute safely on touch screens.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Provide a complete mobile “More” menu, preserve every desktop action, use responsive card/table toggles and horizontal scrolling, make modals full-screen on small devices, and perform device/browser task testing.  
**Priority:** Should Fix

#### W-11 — Accessibility is incomplete

**Problem/Lapse:** Static inspection found 37 image tags but only 11 with explicit `alt`, approximately 480 form controls versus 316 labels, and many icon-only controls. There is no skip link/accessibility statement or automated WCAG test. Color/status meaning is often visual.  
**Why It Matters:** Screen-reader, keyboard, low-vision, and motor-impaired users may not complete essential parish requests.  
**Severity:** 🟠 High  
**Recommended Improvement:** Target WCAG 2.2 AA: programmatic labels/descriptions/errors, meaningful/empty alt text as appropriate, visible focus, keyboard-operable dialogs, focus trapping/restoration, `aria-live` status, non-color status indicators, 44px touch targets, reduced motion, and axe/manual testing.  
**Priority:** Must Fix

#### W-12 — Frontend styling is oversized and inconsistent

**Problem/Lapse:** Core CSS exceeds roughly 540 KB unminified across overlapping theme/design/mobile files, while at least 36 PHP pages add inline styles and 39 add inline scripts. Multiple dashboard and auth variants coexist.  
**Why It Matters:** Cascade conflicts, regressions, slower transfers, inconsistent polish, and a CSP dependent on `unsafe-inline` result.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Consolidate tokens/components into one versioned design system, remove obsolete CSS/routes, extract page modules, minify/bundle critical assets, and add visual regression tests. Preserve the calm blue/gold church identity with restrained ornamentation.  
**Priority:** Should Fix

#### W-13 — Search and reports do not scale consistently

**Problem/Lapse:** Several searches use leading-wildcard `LIKE '%term%'`; report pages run many independent aggregate queries; audit logs are capped at 100 without real pagination; reports cap rows at 25. Full-text exists only for chatbot knowledge.  
**Why It Matters:** Large parish datasets will make headers, dashboards, search and reports slow, while administrators may mistake truncated output for complete data.  
**Severity:** 🟡 Medium  
**Recommended Improvement:** Add query-specific composite/full-text indexes, cursor/page pagination, explicit “showing N of M,” background CSV/PDF export, cached daily aggregates, and EXPLAIN-based query budgets.  
**Priority:** Should Fix

#### W-14 — External calls are coupled to user requests

**Problem/Lapse:** Email, SMS, OCR/face verification, Gemini, and Ollama calls can occur synchronously. Some timeouts reach 45–70 seconds.  
**Why It Matters:** Slow or unavailable services block web workers, cause duplicate submissions on retry, and degrade reliability.  
**Severity:** 🟠 High  
**Recommended Improvement:** Queue external work, use idempotency keys, short request timeouts, circuit breakers, retry/backoff, health dashboards, and user-visible asynchronous states.  
**Priority:** Should Fix

#### W-15 — Testing and release assurance are inadequate

**Problem/Lapse:** Only a conversational-intent test script was found. There are no automated auth, authorization, CSRF, request-state, upload, migration, backup/restore, notification, certificate, browser, accessibility, or load tests. No CI quality gate is evident.  
**Why It Matters:** Security and workflow regressions can ship undetected, especially in a duplicated codebase.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Establish PHPUnit/service tests, MySQL integration tests from a clean database, Playwright end-to-end tests for both roles, OWASP security tests, axe accessibility checks, upload malware/type cases, migration/restore drills, and CI gates for lint/tests/build/dependency audit.  
**Priority:** Must Fix

#### W-16 — Backups and recovery are not production-safe enough

**Problem/Lapse:** The web settings page can create, download, upload, and restore database packages. Backup creation builds SQL in PHP memory and restoration parses SQL itself. There is no demonstrated encryption, off-site copy, signature, restore verification, RPO/RTO, or scheduled drill.  
**Why It Matters:** A CSRF or compromised admin session can overwrite production data; backups can leak the entire parish database; large databases can exhaust memory.  
**Severity:** 🔴 Critical  
**Recommended Improvement:** Move backup/restore to restricted infrastructure jobs using database-native tools; encrypt at rest/in transit with separate key management; sign manifests; store off-site with retention/immutability; require step-up authentication and dual approval for restore; test restores regularly.  
**Priority:** Must Fix

## Workflow audit

### Parishioner journey

| Step | Current assessment | Gap / failure point | Required improvement |
|---|---|---|---|
| Register | Feature-rich but burdensome | Camera, front/back ID, OCR/face JS, Gmail restriction, and biometric risk can block users | Assisted path, normal email support, authoritative server validation, explicit privacy consent |
| Verify / await approval | Implemented | OTP identity is browser-supplied; status lookup enumerates accounts and can expose rejection reasons | Bound pre-auth transaction, generic status lookup, secure case/reference code |
| Log in | Password hashing and active-status check work | No brute-force defense; configured login OTP ignored | Central rate limit, MFA, session bootstrap |
| Submit request | Separate certificate/blessing/service paths work | Different rules and duplicated code; no saved draft/idempotency | Unified wizard/service, drafts, duplicate-submit protection |
| Upload requirements | Modern request uploads validate MIME/size and use private directories | Legacy upload routes remain; no malware scan; multi-write atomicity weak | One upload service, AV/quarantine, transaction/cleanup, per-type policy |
| Track status | My Requests and owned detail checks are good | Status vocabulary lacks next-action states and structured timeline | State machine and clear “what you must do next” card |
| Respond to admin | Payment and some uploads are possible | No two-way conversation or explicit missing-item checklist/resubmission loop | Threaded messages and per-requirement accept/reject/resubmit |
| View announcements | Implemented with attachment controller | Delivery/read targeting is weak and attachment access is all logged-in users | Audience rules, entity links, read receipts where appropriate |
| Make reservation | Implemented | No resource/duration model and incomplete conflict logic | Resource-aware overlap engine and availability calendar |
| Receive/download documents | Modern released files can be attached to requests | Legacy download center is broken; no final artifact signature/receipt | One download center, immutable issued PDF, download audit |
| Manage profile | Profile, password and preferences exist | MFA toggle is ineffective; privacy export/correction/delete absent | Enforce MFA and add privacy/account lifecycle tools |

### Administrator journey

| Step | Current assessment | Gap / failure point | Required improvement |
|---|---|---|---|
| Approve/reject accounts | Available with ID/face review | CSRF missing; sensitive-view audit/retention unclear | CSRF, step-up auth, reason templates, access/download logs |
| Review requests | Unified list and detailed workflow exist | Two overlapping update paths have different validation; no assignment/SLA | One workflow service, assignment, queues, aging/SLA |
| Verify requirements | Documents viewable | No structured per-document decision/version/resubmission | Requirement checklist with reviewer, reason, timestamp, history |
| Update status | Available | Generic enum and inconsistent transition validation; no global transaction | Enforced transition state machine and outbox |
| Communicate | Remarks plus notifications/email/SMS | Free text is not a conversation; delivery recovery is weak | Thread, templates, channel audit, retry worker |
| Announcements | Create/edit/archive/schedule/pin/queue exist | CSRF absent; runtime DDL; delivery mixed into page work | Secure action service and background delivery |
| Reservations/calendar | Available | Resource conflicts/duration not modeled; calendar API lacks CSRF | Resource scheduler and protected API |
| Sacramental records | CRUD/search/export exist | Free-form people data, weak registry uniqueness, runtime DDL | Normalized registry model and immutability controls |
| Generate certificates | Templates/layout/verification exist | Preview equals issuance; incomplete verification types; no artifact signing | Formal approval/issue/revoke lifecycle |
| Reports | Broad dashboard/report categories exist | Query-heavy, truncated results, no scheduled/finalized reports | Paginated/exportable snapshots and background jobs |
| Audit logs | Two table formats are read | Coverage incomplete, mutable DB rows, no export/retention/tamper evidence | One append-only schema, event coverage, protected retention/export |
| Manage users | Available | Only user/admin DB roles; no consistent least privilege | Database-backed RBAC and role tests |
| Monitor activity | Dashboard and integration health exist | No alerts, uptime/latency/error-rate metrics, job queue status | Central observability and alert thresholds |
| Handle incomplete/rejected work | Possible through remarks/status | No dedicated `needs_information` loop or reopen/escalate action | Structured exception queue with resubmission/reopen/escalation |

## Database suitability conclusion

The current database is suitable for a prototype with a small dataset, but not yet for a real parish records system. Primary keys exist consistently and several high-value indexes/unique constraints are present. The live sample has no detected orphans. These positives are outweighed by absent foreign keys on much of the expanded schema, weak registry uniqueness, denormalized participant data, inconsistent soft deletion, dangerous cascade semantics, nullable fields without business constraints, race-prone numbering, limited transaction use, and severe schema drift.

Before deployment, produce a reviewed data dictionary covering every field, classification, nullability, validation rule, owner, retention period, index, relationship, and deletion rule. Migrate a scrubbed production-sized dataset in staging and validate constraints before enabling them.

## Missing features

### Essential before deployment

- Enforced MFA for administrators and step-up authentication for recovery, exports, and sensitive identity views.
- Database-backed RBAC with least-privilege staff roles and an authorization matrix.
- Unified request state machine, assignment, SLA/aging, structured requirements, and parishioner response thread.
- Resource-aware reservation engine with duration, location/minister/resource conflicts, and blackout periods.
- Privacy consent, retention, access/correction/export, secure disposal, and breach-response processes.
- Encrypted, off-site, tested backups with restricted restore procedure.
- Background job/outbox processing for email, SMS, document generation, OCR/face work, and AI.
- Malware scanning/quarantine and one hardened upload/download subsystem.
- Immutable certificate issue/revoke/reissue workflow with final artifact hash and QR verification.
- Deployment migrations, observability, alerting, CI, automated security/workflow/accessibility tests.

### Recommended

- Admin workload assignment, internal notes, escalation, due dates, and queue dashboards.
- Configurable parish services, requirements, fees, chapel districts, schedules, and effective dates.
- Notification templates, localization, delivery retries, channel preferences, and deep links.
- Saved drafts, idempotency tokens, duplicate-request detection, and cancellation/withdrawal.
- Responsive table/card switcher, full mobile “More” navigation, and WCAG 2.2 AA remediation.
- Proper audit export, immutable retention, before/after values, correlation/request IDs.
- Scheduled reports, comparison periods, turnaround/SLA metrics, and data-quality dashboards.
- Dependency vulnerability scanning and a software bill of materials.

### Future enhancement

- Diocese-approved interoperability/export formats and controlled external integrations.
- Digital signing with managed parish keys and optional qualified timestamping.
- Appointment waitlist, resource optimization, calendar feeds, and reminder automation.
- Human-reviewed AI feedback loop, semantic retrieval, multilingual policy evaluation, and admin citation workflow.
- Disaster-recovery failover, read replica/analytics warehouse, and archival storage tiers.

## Prioritized roadmap

### Phase 1 — Critical fixes (target: 2–4 weeks)

1. Remove/disable installers, password fixers, debug routes, demos, and legacy certificate endpoints; rotate admin credentials.
2. Add mandatory bootstrap, server-side permission middleware, and CSRF protection to every mutation.
3. Implement login/OTP rate limits, bound pre-auth transactions, administrator MFA, and secure session regeneration.
4. Fix the Admin AI API contract or disable the feature until tested.
5. Freeze page-level DDL; establish one migration baseline/ledger and add missing FKs after data validation.
6. Protect backups and sensitive uploads outside the web root; document privacy/retention controls.
7. Add smoke/integration tests for login, authorization, CSRF, request ownership, admin mutations, and clean installation.

**Exit gate:** No default credentials/web installers; every mutating route passes CSRF/authorization tests; clean install produces the same schema; admin MFA works; backup and sensitive-file access are restricted; critical automated tests pass.

### Phase 2 — Major improvements (target: 4–8 weeks)

1. Build the unified request state machine, requirement review/resubmission, assignment, messaging, and audit history.
2. Rebuild reservations around resources/durations/overlaps.
3. Normalize key sacramental registry fields and define official uniqueness/immutability rules.
4. Implement outbox/workers for messages and external services.
5. Complete certificate draft/approve/issue/release/revoke and all-type verification.
6. Consolidate RBAC, audit logs, errors, uploads, and legacy routes.
7. Complete WCAG/mobile task remediation and performance query/index work.

### Phase 3 — Enhancements (target: 3–6 weeks)

1. Versioned, cited, effective-dated AI knowledge with bilingual factuality evaluation.
2. Notification deep links/templates/retry dashboards and scheduled report exports.
3. UI design-system consolidation, bundling, visual regression tests, and offline-friendly fallbacks.
4. Admin SLA/quality dashboards and data-quality cleanup tools.

### Phase 4 — Future development

1. Diocese integrations and standardized archival exports.
2. Managed digital signatures and timestamping.
3. Advanced analytics, forecasting, waitlists, and resource optimization.
4. Tested failover, archive tiers, and expanded multilingual/semantic AI.

## Scorecard

| Category | Score | Explanation |
|---|---:|---|
| Functionality | 6/10 | Broad modules exist, but Admin AI and legacy certificate paths are broken and workflow states are incomplete. |
| Usability | 6/10 | Dashboards, forms and notifications are generally understandable; fragmented workflows and high-friction registration remain. |
| Reliability | 4/10 | Limited transactions, synchronous integrations, runtime DDL, schema drift, and negligible automated coverage. |
| Performance | 5/10 | Pagination exists in core lists and the Vite bundle is reasonable; repeated schema introspection, many aggregate queries, large CSS and synchronous calls will not scale. |
| Security | 3/10 | Password hashing and many prepared statements are positive, but CSRF gaps, no login throttling, ineffective MFA, public credential utilities, and inconsistent sessions are blockers. |
| Database Quality | 4/10 | Clear core entities and some indexes exist; expanded live tables lack many FKs, registry constraints, consistent migrations and normalization. |
| UI/UX | 6/10 | Calm blue/gold visual direction and reusable components are present; duplicated/inline styles and inconsistent standalone pages reduce polish. |
| Responsiveness | 6/10 | Extensive breakpoints and mobile navigation exist, but full task parity and admin table usability are not proven. |
| AI Quality | 4/10 | Bilingual heuristics, fallback engines and a knowledge base are useful; admin access is broken and policy facts remain hard-coded/weakly grounded. |
| Maintainability | 3/10 | Large mixed-concern PHP pages, duplicate modules, runtime schema mutation and multiple design/auth/audit systems create substantial technical debt. |
| Deployment Readiness | 2/10 | Container/health/build files exist, but security, privacy, migration, backup, test and observability gates are not met. |

**Overall System Score: 4.5/10**  
**Current System Status: Needs Major Improvement**

## TOP 20 THINGS WE MUST FIX IN TUGON

1. **Public setup/debug/default-admin routes →** They enable or reveal administrative compromise → Remove them from the web artifact, rotate credentials, and use a one-time CLI admin bootstrap with forced password change → **Must Fix**.
2. **Missing CSRF on mutations →** A logged-in victim can be forced to approve, delete, restore, upload, or alter records → Apply mandatory CSRF middleware to every POST/PUT/PATCH/DELETE route and test the route inventory → **Must Fix**.
3. **No real login throttling →** Credential stuffing can target all accounts → Enforce shared per-IP and per-identifier rate limits, lockout/delay, generic errors, audit and alerts → **Must Fix**.
4. **Ineffective administrator MFA and unsafe OTP binding →** Account protection shown in the system is not actually enforced → Use server-bound pre-auth transactions, verified destinations, resend/attempt limits, session regeneration, and mandatory admin MFA → **Must Fix**.
5. **Undefined sensitive-data privacy lifecycle →** IDs, faces, receipts, sacramental data, AI text and backups may be retained or exposed improperly → Complete a privacy impact assessment and implement classification, consent, minimization, retention, access, export, audit, deletion and incident response → **Must Fix**.
6. **Missing database foreign keys →** Orphan payments, files, notifications and official-record links can silently appear → Add indexed, reviewed FKs with deliberate restrict/set-null/cascade rules after staging cleanup → **Must Fix**.
7. **Multiple conflicting schema installers/runtime DDL →** Deployments cannot reliably reproduce the same database → Establish one checksummed migration ledger, clean-install test and CLI deployment job; remove all page-level DDL → **Must Fix**.
8. **Unsafe web-based backup/restore →** A compromised admin session or CSRF can expose/overwrite the entire parish database → Move encrypted backups/restores to restricted infrastructure jobs with step-up auth, dual approval, off-site immutability and restore drills → **Must Fix**.
9. **Broken Admin AI contract →** A headline feature returns 403/CSRF failure → Permit a defined admin API role, send CSRF correctly, scope data by permission, and add role contract tests—or disable it → **Must Fix**.
10. **Hard-coded AI parish policies →** The bot can state outdated requirements as official → Store only approved, cited, versioned, effective-dated knowledge; retrieve it at runtime and refuse uncertain answers → **Must Fix**.
11. **Fragmented request statuses and response loop →** Parishioners cannot clearly satisfy missing requirements and admins cannot manage exceptions efficiently → Implement an enforced request state machine, per-item verification, resubmission, messaging, assignment, due dates and full history → **Must Fix**.
12. **Reservation conflicts ignore resources/duration →** Different service types can double-book the same church or minister → Model resources/start/end/buffers/blackouts and perform transactional overlap checks → **Must Fix**.
13. **Legacy certificate/upload subsystem remains reachable →** It references absent tables and bypasses modern security conventions → Remove/410 it and migrate all links to the single modern request/document/payment workflow → **Must Fix**.
14. **Certificate preview is treated as issuance →** Official-document history can be inaccurate and altered PDFs cannot be proven → Separate draft/approval/issue/release/revoke, hash/sign the final artifact, support all types in QR verification, and preserve reissue history → **Must Fix**.
15. **Least-privilege roles exist only in PHP →** Staff cannot be safely delegated work → Normalize database roles/permissions, replace direct `role === admin` checks, and test a route/action authorization matrix → **Must Fix**.
16. **Multi-step writes are not atomic →** Requests, documents, calendar events and notifications can disagree after partial failure → Use DB transactions, filesystem compensation, idempotency keys and an outbox worker → **Must Fix**.
17. **No meaningful automated release suite →** Security and workflow regressions will ship unnoticed → Add clean-schema integration tests, PHPUnit/service tests, Playwright journeys, authorization/CSRF/upload cases, axe checks and CI gates → **Must Fix**.
18. **Accessibility gaps in images, labels, dialogs and status feedback →** Users with disabilities can be blocked from parish services → Remediate to WCAG 2.2 AA and verify with axe plus keyboard/screen-reader testing → **Must Fix**.
19. **Synchronous email/SMS/AI/OCR calls →** Slow integrations block submissions and cause duplicates → Queue them with idempotency, timeout, retry/backoff, circuit breakers and visible pending/failure states → **Should Fix**.
20. **Duplicated controllers/styles/auth/audit systems →** Every security and UX correction must be repeated and is easily missed → Consolidate middleware, services, repositories, views, design tokens and audit schema; delete deprecated paths → **Should Fix**.

## Deployment and academic presentation recommendation

### Actual parish deployment

Do **not** deploy TUGON with real parishioner or sacramental data yet. Phase 1 exit gates are mandatory, followed by a staging pilot using synthetic data, an independent security review, privacy/legal review, backup restore drill, role/ownership test, accessibility test, and supervised user-acceptance test with parish staff. Production should start as a limited pilot with monitoring and a rollback plan.

### Academic/capstone presentation

The breadth of the prototype is strong enough for a capstone demonstration: it covers registration review, requests, requirements/payments, scheduling, records, certificates, notifications, reports, audit logs, and AI. Before presenting:

1. Remove every known-credential/setup/debug route from the demo server.
2. Fix or disable Admin AI so no advertised feature fails live.
3. Demonstrate ownership and role denial tests, CSRF protection, MFA, and private document access—not merely UI screens.
4. Present the database migration/constraint plan and privacy/retention model honestly.
5. Use synthetic identities and records only; never show real IDs, face captures, phone numbers, receipts, or sacramental data.
6. Show automated test evidence and a clean-install deployment rather than relying only on manual screenshots.
7. Describe TUGON as a **functional prototype undergoing security and data-governance hardening**, not a deployment-ready parish records platform.

That framing is credible and professionally defensible. Claiming current production readiness would not be.
