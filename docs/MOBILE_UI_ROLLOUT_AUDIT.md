# Compact Mobile UI Rollout Audit

Scope: phone view (`max-width: 599px`). Tablet and desktop layouts retain their existing component sizing and structure.

## Legacy patterns found

- Dashboard: search-heavy header and full-width stacked navigation cards.
- Request tracking: duplicate page title/back row, inline “New Request” banner, desktop table presentation.
- Profile and account settings: desktop card spacing, oversized fields, multiple large section headings.
- Certificate, blessing, and sacramental-service requests: hero/intro banners, status widgets, numbered step circles, large option cards, and inline submit actions.
- Reservations: desktop card/form spacing and a submit action buried after the form.
- Schedule, announcements, and notifications: large hero blocks and desktop-scale control typography.
- Payments, requirements, reservation history, and request-detail screens: desktop cards/tables and inconsistent field/control density.
- Registration identity verification: three large numbered/status blocks instead of a compact progress rail.
- Password recovery, OTP, and email verification: standalone authentication layouts with inconsistent phone field and action sizing.
- Administrator phone screens: desktop-scale heroes, KPI cards, forms, and global header controls.

## Shared system implemented

- `MobileTopbar`: the shared user/admin application header becomes a sticky condensed title/subtitle/profile bar. Existing context-back behavior supplies the compact chevron.
- `mobileStepRail()`: reusable PHP renderer for dot/line progress rails, used by certificate, blessing, and sacramental-service request flows.
- `CompactCard`: shared card tokens, soft shadow, 12px radius, compact document/list presentation, and two-column option grids.
- `CompactInput`: shared 42px minimum controls, 12px text, neutral fill, gold focus border, and soft focus ring.
- `StickyCTA`: fixed request/reservation submission bars with safe-area padding.
- Shared phone type scale for labels, descriptions, cards, metadata, and controls.
- Registration verification steps restyled as a dot/line rail without changing its JavaScript state model.

## Migrated screen groups

- Parishioner dashboard and feature navigation.
- Certificate, blessing, and sacramental-service request forms.
- My Requests and request-detail family.
- Profile, change-password, and account preferences.
- Reservations and reservation history.
- Schedule, announcements, notifications, and AI assistant shell.
- Payments and requirement submission/history screens.
- Login, registration, password recovery, OTP, and email verification.
- Administrator dashboard and operational screens at phone width.

## Validation boundary

- No request, upload, CSRF, database, status, payment, or notification backend logic was removed.
- Shared styles activate only below 600px.
- Tablet and desktop component rules are not modified by the new stylesheet.
