# Stage 7 frontend integration map

This map describes the current frontend/API wiring. A connected UI means the
page calls the endpoint; it does not mean every action has been tested with
authenticated production roles or that a physical gate event occurred.

## Shared behavior

- `js/data/api-client.js` derives the same-origin API base URL from its own
  script URL, sends session cookies, reads the standard
  `{ success, message, data }` envelope, and sends the CSRF token for POSTs.
- `js/app.js` checks `api/auth/me.php` before protected pages, routes using the
  actual server role and gate assignments, loads permitted data, and exposes
  sign-out and notifications. The role selector on the login screen is only a
  visual choice; it does not grant authorization.
- `js/admin/admin.js`, `js/entry/entry.js`, `js/exit/exit.js`, and
  `js/components/gate-controls.js` render/use the API-backed data facade.
  Authorization and gate/direction rules remain server-side.

## Page and endpoint map

| Page(s) | Connected behavior / APIs | Remaining gaps |
| --- | --- | --- |
| `pages/auth/login.html` | Login, session, CSRF, logout: `api/auth/csrf.php`, `login.php`, `me.php`, `logout.php` | Authenticated login/logout and account lockout/rate limits were not tested in this pass. |
| `pages/admin/dashboard.html`, `pages/entry/dashboard.html`, `pages/exit/dashboard.html` | `api/dashboard/index.php`; live summary values, recent gate events/approvals/camera inventory where available | Dashboard values are scoped and based on backend event records. No physical presence sensor exists. |
| `pages/admin/approvals.html` and dashboard approval panel | `api/approvals/index.php`, `view.php`, `decision.php`; status tabs, gate filter, pending/decision history and denial reason | Review decisions have not been exercised with real Admin accounts. Some request types have limited detail fields in the current UI. |
| `pages/admin/schedules.html`, `pages/entry/schedules.html`, `pages/exit/schedules.html` | `api/schedules/`; create/update/cancel schedule; date/company/status filters; display expected visits | Officer schedule pages are read-only. Schedules are not gate events. |
| `pages/admin/vehicles.html`, `pages/entry/vehicles.html`, `pages/exit/vehicles.html` | `api/vehicles/`; list, search, company/status filters, status update where permitted | Vehicle create/driver/company setup uses the Admin Operations page. Role restrictions require live verification. |
| `pages/admin/visitors.html` | `api/visitors/`, `api/approvals/`; visitor register/edit/status, pass request/approval, date/pass-status filters, history, raw token after successful issue, revoke | QR validation is available only through the gate validation form/API; there is no scanner. |
| `pages/exit/fill-form.html` | `api/goods/`, `api/approvals/`; multi-item submission, saved-draft retry/cancel, status refresh, and approved-exit authorization attempt | Browser-validated against the isolated Stage 7 database: pending, approved, denied, cancelled, duplicate-submit guard, stale-ID cleanup, and saved draft restore/reload are working. The form continues to map extra fields into item remarks because the backend schema does not expose dedicated columns for them. |
| Entry/exit dashboards and logistics pages | `api/gate-operations/gates.php`, `expected.php`, `authorize.php`, `events/`; manual plate observation and authorization attempt, event search | No camera/ANPR, QR scanner, or barrier control. A recorded authorized event is not evidence of physical crossing. UI period/shift filters are still presentation-only; event list is today-scoped. |
| `pages/admin/cameras.html` | `api/cameras/`; metadata registration, status/monitoring metadata | No live video or hardware connection. |
| `pages/admin/users.html` | `api/users/`; user list/search, role/gate/status filters | The displayed shift column is not backed by a per-user shift history; shift administration is on Operations. |
| `pages/admin/operations.html` | `api/companies/`, `drivers/`, `gates/`, `lanes/`, `users/`, `shifts/`, `reports/`, `notifications/`, `audit/` | Admin supporting interface is intentionally compact. Shift conflict/business-policy cases and every CRUD validation path need authenticated testing. |
| All protected pages | `api/notifications/`; user-scoped notification list and mark-read actions when permitted | Notification delivery is database-backed only; no email/SMS/push delivery. |
| Admin Operations reports/audit | `api/reports/`, `api/audit/`; filters, CSV download, paginated immutable audit history | CSV/report coverage depends on the selected backend report type. PDF output is a client-generated printable summary, not an authoritative pass or backend report. |

`api/README.md` contains the complete backend endpoint/permission inventory.

## Workflows and data compatibility

- Vehicle access uses an existing registered vehicle, assigned gate/lane,
  direction, optional schedule, and an idempotency key before posting to gate
  operations. The backend decides authorization.
- Visitor registration, pass issuance, approval, token validation, and physical
  visitor entry are distinct. The raw QR token must be retained only by the
  authorized issuer; the UI presents it after issuance and does not persist it
  in browser storage.
- Goods declarations are submitted with multiple items and use the existing
  approval lifecycle. An approved declaration is not a recorded exit.
- Existing UI date/shift controls on logistics pages do not currently drive
  server filters; the current event list uses today's date. Dashboard totals
  use the backend's last-24-hours/current-presence period.
- PDF output can be printed/saved by the browser and is generated from provided
  UI data. It is explicitly a convenience summary and is not proof of
  approval or physical passage.

## Verification completed and outstanding

Completed checks to date:

- `node --check` passed for the relevant exit goods and shared front-end JavaScript after the status-handling fix.
- The public health endpoint responded HTTP 200; PHP, database-server, and database checks reported `ok`.
- Browser login succeeded for the real Stage 7 Officer account (`stage7_browser_officer_20261115`) and the real Stage 7 Admin account (`stage7_browser_admin_20261115`) against the isolated Stage 7 database.
- The Officer exit dashboard and the authenticated `pages/exit/fill-form.html` flow rendered correctly with assigned gate/lane data and a valid registered vehicle (`ET-5555`).
- The exit goods flow was exercised end-to-end in the browser: draft creation, pending state, reload/restore, duplicate-submit guard, admin approval, admin rejection, officer-side cancellation, stale-ID cleanup, and final status rendering were all verified against the isolated Stage 7 database.
- Admin approvals were exercised through the real approvals UI and the underlying `approval_requests` records transitioned to `approved` / `denied` as expected.
- The real Stage 7 records show the controlled workflow transitions: `goods_declarations` were observed in `pending`, `approved`, `denied`, and `cancelled` states, and stale saved-draft IDs were cleared once declarations left the draft/pending lifecycle.

Not completed / still bounded:

- This does not claim complete Stage 7 coverage for every non-goods admin/officer workflow in the project; the verified scope is the authenticated exit goods declaration lifecycle and the specific admin approval decisions that drive it.
- The broader handoff backlog (visitor/pass escalation, schedules, users, operations, and reporting) remains tracked separately and is not treated as complete solely on the basis of this goods flow validation.
- The Stage 7 browser verification used the isolated database and test accounts only; no production data was modified.
- A complete before/after reference-project comparison remains out-of-scope for this specific continuation unless the handoff explicitly asks for it again.
