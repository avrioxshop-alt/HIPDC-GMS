# HIPDC-GMS PHP Backend

The backend uses PHP 8.1+, PDO, MySQL/MariaDB, native PHP sessions, and JSON
responses. The existing HIPDC frontend uses this backend through the
same-origin API client documented in `../docs/stage7-integration-map.md`.
The operational APIs record companies, drivers, vehicles, gates/lanes,
schedules, approval workflows, and server-side gate access attempts. The
Stage 5 event log records authorized/manual or authenticated-device reports;
it does not itself confirm that a person or vehicle physically crossed a
barrier. No camera, QR scanner, ANPR, or gate controller is configured.

## Structure

- `api/health.php` — PHP, database-server, and configured-database checks.
- `api/auth/` — CSRF token, login, logout, and current-user endpoints.
- `api/users/` — administrator-only user, role, status, password, and gate assignment APIs.
- `api/companies/`, `api/drivers/`, `api/vehicles/` — company, driver, and vehicle registries.
- `api/gates/`, `api/lanes/` — gate definitions and their directional lanes.
- `api/schedules/` — expected vehicle schedules, separate from actual entry/exit.
- `api/approvals/` — pending requests, decisions, and decision history.
- `api/visitors/` — visitor records, pass requests, QR validation, and revocation.
- `api/goods/` — goods declaration drafts, items, submission, and cancellation.
- `api/gate-operations/` — gate authorization, access attempts/events,
  expected arrivals, presence, history, and activity summaries.
- `api/gate-devices/` — administrator device registration and deactivation.
- `api/dashboard/`, `api/shifts/`, `api/cameras/`, `api/reports/`,
  `api/notifications/`, and `api/audit/` — Stage 6 operational summaries,
  staff scheduling, camera metadata, reports, user notifications, and
  read-only audit history.
- `config/app.php` — application, response, session, and error settings.
- `config/database.php` — environment-based PDO connection helpers.
- `includes/bootstrap.php` — API error handling and shared initialization.
- `includes/auth.php` — authenticated-user and permission/gate authorization helpers.
- `includes/csrf.php`, `includes/response.php`, `includes/session.php` — shared security and response helpers.
- `includes/user_management.php` — user validation and management helpers.
- `includes/operations.php` — operational validation, relationship, permission, and audit helpers.
- `includes/visitor_goods.php` — visitor/pass and goods/declaration validation and record helpers.
- `includes/gate_operations.php` — centralized gate policy, idempotency,
  anti-passback, and immutable event recording.
- `includes/gate_devices.php`, `includes/gate_device_adapters.php` — device
  authentication and hardware adapter contracts; no hardware is configured.
- `includes/supporting.php` — Stage 6 shift, camera, and notification helpers.
- `database/create_database.sql` — creates the empty `hipdc_gms` database.
- `database/migrations/002_auth_and_users.sql` — authentication, role, permission, and gate-assignment schema.
- `database/migrations/003_core_operations.sql` — normalized operational schema and module permissions.
- `database/migrations/004_visitors_and_goods.sql` — visitor/pass and goods schemas and approval links.
- `database/migrations/005_gate_operations.sql` — access events, device
  credentials, and gate-operation permissions.
- `scripts/create_admin.php` — creates the first active Admin from the command line.

## XAMPP setup and database

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Create the database if needed by importing `database/create_database.sql`
   in phpMyAdmin or running it with the MySQL client.
3. Configure the database environment variables for Apache, then restart it:

   | Variable | Default |
   | --- | --- |
   | `HIPDC_DB_HOST` | `127.0.0.1` |
   | `HIPDC_DB_PORT` | `3306` |
   | `HIPDC_DB_NAME` | `hipdc_gms` |
   | `HIPDC_DB_USER` | `root` |
   | `HIPDC_DB_PASSWORD` | empty |

   Defaults are for local XAMPP only. Production must use a least-privilege
   database account and protected runtime secrets.
4. Apply the Stage 2 migration once, from Command Prompt:

   ```cmd
   "C:\Users\happy22\xampp\mysql\bin\mysql.exe" -u root hipdc_gms < "C:\Users\happy22\xampp\htdocs\HIPDC-GMS\database\migrations\002_auth_and_users.sql"
   ```

   Add `-p` if the MySQL account requires a password. The migration creates
   `roles`, `permissions`, `role_permissions`, `users`, `gates`, and
   `user_gate_assignments`. It seeds only the Admin/Officer roles and their
   baseline permissions; it does not create gates or other operational data.

Optional application/session variables:

- `HIPDC_APP_ENV`: `development` or `production` (default: `development`).
- `HIPDC_SESSION_PATH`: session cookie path override (default is derived from
  the installation path, such as `/HIPDC-GMS/`).

## Create the initial administrator

Run this in an interactive Command Prompt or PowerShell window:

```powershell
& 'C:\Users\happy22\xampp\php\php.exe' 'C:\Users\happy22\xampp\htdocs\HIPDC-GMS\scripts\create_admin.php'
```

The script asks for username, full name, email, and password. The password
prompt is hidden in an interactive terminal. Use a unique password of 12-72
bytes. The script refuses to create the initial account when an active Admin
already exists, hashes the password with `password_hash()`, and never prints
or logs it. Do not run it through a command that records the password in shell
history.

## Authentication and CSRF flow

1. `GET /HIPDC-GMS/api/auth/csrf.php` creates or returns a token in the
   anonymous PHP session.
2. Send that token in the `X-CSRF-Token` header for `POST` requests, including
   login and logout. JSON endpoints require `Content-Type: application/json`.
3. `POST /HIPDC-GMS/api/auth/login.php` accepts `username` and `password`.
   Successful login regenerates the session ID and rotates the CSRF token.
   The browser should retain the `HIPDCSESSID` cookie; it is HttpOnly,
   SameSite=Lax, scoped to this installation, and Secure over HTTPS.
4. `GET /HIPDC-GMS/api/auth/me.php` returns the authenticated user, actual
   role, server-side permissions, and gate assignments. It returns HTTP 401
   for missing, inactive, or revoked sessions.
5. `POST /HIPDC-GMS/api/auth/logout.php` requires a CSRF token and destroys
   the session and cookie.

All responses use `{ "success": boolean, "message": string, "data": object }`
when data is present. Login failure responses do not disclose whether the
username exists. Password hashes and internal exception details are never
returned.

## Roles, permissions, and gate assignments

The migration seeds two login roles: `Admin` and `Officer`. Role permissions
are stored in `role_permissions`; clients cannot grant themselves roles or
permissions. Admin has the user-management and gate-direction permissions.
Officer has the baseline `gate.entry` and `gate.exit` permissions, with
effective gate access further limited by each `user_gate_assignments` row.

Each user can have at most one assignment per gate. Its `direction` is
`entry`, `exit`, or `both`. Gate operations must call the shared
`requireGateAccess($gateId, $direction)` authorization helper; it checks the
active account, role permission, active gate, and assignment server-side.
No actual gates are seeded. Admins can only assign gates that have been
created in the `gates` table.

## API endpoints

All URLs below are relative to `/HIPDC-GMS/`. State-changing requests use
`POST`, JSON bodies, and `X-CSRF-Token`.

| Endpoint | Access | Purpose |
| --- | --- | --- |
| `GET api/health.php` | Public | Report PHP/MySQL/database availability. |
| `GET api/auth/csrf.php` | Public | Issue the session-bound CSRF token. |
| `POST api/auth/login.php` | Public + CSRF | Authenticate with username and password. |
| `POST api/auth/logout.php` | Session + CSRF | Destroy the current session. |
| `GET api/auth/me.php` | Authenticated | Return the current user, role, permissions, and gate assignments. |
| `GET api/users/index.php` | Admin, `users.view` | List users; supports `page`, `per_page`, `status`, and `q`. |
| `POST api/users/index.php` | Admin, `users.create` | Create active user. Body: `username`, `name`, `email`, `password`, `role_id`. |
| `GET api/users/view.php?id={id}` | Admin, `users.view` | View a user, role permissions, and gate assignments. |
| `GET api/users/options.php` | Admin, `users.view` | Return role IDs/permissions and active gates for management forms. |
| `POST api/users/update.php?id={id}` | Admin, `users.update` | Update `username`, `name`, and `email`. |
| `POST api/users/status.php?id={id}` | Admin, `users.manage_status` | Set body `status` to `active` or `inactive`; status changes revoke existing sessions. |
| `POST api/users/role.php?id={id}` | Admin, `users.assign_role` | Set body `role_id`; self-role changes and last-Admin demotion are rejected. |
| `GET api/users/gates.php?id={id}` | Admin, `users.assign_gates` | List a user's gate assignments. |
| `POST api/users/gates.php?id={id}` | Admin, `users.assign_gates` | Replace assignments with body `{ "assignments": [{ "gate_id": 1, "direction": "entry" }] }`. |
| `POST api/users/password.php?id={id}` | Admin, `users.change_password` | Change body `password`; revokes prior authenticated sessions for that account. |

User creation and password changes require passwords of 12-72 bytes. Duplicate
usernames/emails return HTTP 409. Invalid input returns HTTP 422; unauthenticated
requests return HTTP 401; authenticated non-admin requests return HTTP 403.
Attempting to deactivate or demote the last active administrator returns HTTP
409. Administrative changes are written to the PHP error log without secrets.

## Test the health endpoint

With XAMPP running, open
`http://localhost/HIPDC-GMS/api/health.php`. HTTP 200 reports PHP, MySQL server,
and database as `ok`; an unavailable dependency returns HTTP 503 with sanitized
status data.

## Security and operations notes

PHP API errors are logged but not exposed to clients. No unrestricted CORS is
enabled. Use HTTPS in production. Configure web-server or upstream rate limits
for login attempts before public deployment; this stage does not add a
distributed login-throttling service. Stage 5 records authorized access
attempts, but camera integration and physical barrier control are not enabled.

## Stage 3 operational schema and migration

After applying migration `002_auth_and_users.sql`, apply
`database/migrations/003_core_operations.sql` once:

```cmd
"C:\Users\happy22\xampp\mysql\bin\mysql.exe" -u root hipdc_gms < "C:\Users\happy22\xampp\htdocs\HIPDC-GMS\database\migrations\003_core_operations.sql"
```

Add `-p` if MySQL requires a password. The migration extends the existing
Stage 2 `gates` table with description, location, and supported-direction
flags. It does not create a replacement gates table or seed any operational
records.

New tables and relationships:

- `companies` — company registration/contact details; referenced by drivers,
  vehicles, schedules, and approval requests.
- `drivers` — identity, license, and optional company; identity and license
  numbers are individually unique when present.
- `vehicles` — company, optional assigned driver, vehicle/registration details,
  and status. Plate text is normalized (uppercase, ignoring spaces and
  hyphens) into a unique key to prevent formatting-based duplicates.
- `lanes` — child of the existing `gates` table, unique by code and name per
  gate, with entry/exit/both direction.
- `schedules` — references vehicle and driver and optionally company, gate, and
  lane. A schedule is an expected visit only; its only Stage 3 statuses are
  `scheduled` and `cancelled`.
- `approval_requests` — generated unique reference, request type, submitted
  user, optional reviewer, related operational records, pending/decision
  status, and decision fields.
- `approval_decisions` — append-only decision/cancellation history per request.
- `audit_events` — actor/action/entity history for operational mutations.

Foreign keys use restrictive deletion for records referenced by schedules or
approval history; deactivate records instead of deleting referenced records.
Operational create/update/status changes and approval decisions write audit
events in their transaction. Timestamps use MySQL server time; schedule
`scheduled_at` values are interpreted as local park time and stored as
`DATETIME`.

## Stage 3 permissions and endpoint access

Migration 003 adds these permission groups to the existing Stage 2 permission
table (and grants them without removing existing role assignments):

- `companies.view`, `companies.create`, `companies.update`,
  `companies.manage_status`
- `drivers.view`, `drivers.create`, `drivers.update`,
  `drivers.manage_status`
- `vehicles.view`, `vehicles.create`, `vehicles.update`,
  `vehicles.manage_status`
- `gates.view`, `gates.create`, `gates.update`, `gates.manage_status`
- `schedules.view`, `schedules.create`, `schedules.update`, `schedules.cancel`
- `approvals.view`, `approvals.submit`, `approvals.decide`,
  `approvals.assign`, `approvals.cancel_own`

Admin receives all new permissions. Officer receives registry and gate
read-only permissions, schedule view/create/cancel (cancel is limited to the
officer's own schedules), and approval view/submit/cancel-own (requests are
scoped to the submitting officer). Approval decisions and registry/gate
mutations are Admin-only. A user must also have the relevant `gate.entry` or
`gate.exit` permission and a matching active gate assignment to create or
operate on a gate-specific schedule or approval. This rule also applies to an
Admin; assign administrators to gates they need to review.

All endpoints below are relative to `/HIPDC-GMS/`. List endpoints support
`page` (default 1), `per_page` (default 25, max 100), and `q` search. Mutations
use JSON, `POST`, the session cookie, and `X-CSRF-Token`.

| Endpoint | Required permission | Purpose |
| --- | --- | --- |
| `GET/POST api/companies/index.php` | `companies.view` / `companies.create` | Search/list or create companies. |
| `GET api/companies/view.php?id={id}` | `companies.view` | Read one company. |
| `POST api/companies/update.php?id={id}` | `companies.update` | Update company contact/registration details. |
| `POST api/companies/status.php?id={id}` | `companies.manage_status` | Set status to `active` or `inactive`. |
| `GET/POST api/drivers/index.php` | `drivers.view` / `drivers.create` | List/search or create drivers. Optional list filter: `company_id`. |
| `GET api/drivers/view.php?id={id}` | `drivers.view` | Read one driver. |
| `POST api/drivers/update.php?id={id}` | `drivers.update` | Update driver details and company. |
| `POST api/drivers/status.php?id={id}` | `drivers.manage_status` | Activate/deactivate a driver. |
| `GET/POST api/vehicles/index.php` | `vehicles.view` / `vehicles.create` | List/search or register a vehicle. Optional list filter: `company_id`. |
| `GET api/vehicles/view.php?id={id}` | `vehicles.view` | Read one vehicle. |
| `POST api/vehicles/update.php?id={id}` | `vehicles.update` | Update vehicle details and assignment. |
| `POST api/vehicles/status.php?id={id}` | `vehicles.manage_status` | Set `active`, `inactive`, or `blocked`. |
| `GET/POST api/gates/index.php` | `gates.view` / `gates.create` | List/search or create gates. |
| `GET api/gates/view.php?id={id}` | `gates.view` | Read a gate and its lanes. |
| `POST api/gates/update.php?id={id}` | `gates.update` | Update gate details and supported directions. |
| `POST api/gates/status.php?id={id}` | `gates.manage_status` | Activate/deactivate a gate. |
| `GET/POST api/lanes/index.php` | `gates.view` / `gates.create` | List/search lanes or create one under a gate. |
| `GET api/lanes/view.php?id={id}` | `gates.view` | Read one lane. |
| `POST api/lanes/update.php?id={id}` | `gates.update` | Update lane and parent gate. |
| `POST api/lanes/status.php?id={id}` | `gates.manage_status` | Activate/deactivate a lane. |
| `GET api/schedules/index.php` | `schedules.view` | Search schedules; optional status: `scheduled` or `cancelled`. |
| `POST api/schedules/index.php` | `schedules.create` | Create an expected schedule. |
| `GET api/schedules/view.php?id={id}` | `schedules.view` | Read one schedule subject to gate/owner scope. |
| `POST api/schedules/update.php?id={id}` | `schedules.update` | Update a scheduled (not cancelled) schedule. |
| `POST api/schedules/status.php?id={id}` | `schedules.cancel` | Transition `scheduled` to `cancelled`; officers can cancel their own. |
| `GET api/approvals/index.php` | `approvals.view` | Search/list requests; optional status filter. |
| `POST api/approvals/submit.php` | `approvals.submit` | Create a `pending` request; never auto-approves. |
| `GET api/approvals/view.php?id={id}` | `approvals.view` | Read request and decision history within role scope. |
| `POST api/approvals/assign.php?id={id}` | `approvals.assign` + Admin role | Assign or clear `reviewer_id`; reviewer must be an active authorized Admin. |
| `POST api/approvals/decision.php?id={id}` | `approvals.decide` + Admin role | Transition pending request to `approved` or `denied`. |
| `POST api/approvals/cancel.php?id={id}` | `approvals.cancel_own` | Submitter cancels their own pending request. |

Example company:

```json
{
  "name": "Example Logistics",
  "registration_number": "REG-12345",
  "contact_person": "Operations Desk",
  "phone": "+251 911 000000",
  "email": "ops@example.com",
  "address": "Hawassa Industrial Park"
}
```

Example vehicle:

```json
{
  "company_id": 1,
  "assigned_driver_id": 1,
  "plate_number": "SNNP 3-A48217",
  "vehicle_type": "truck",
  "make": "Isuzu",
  "model": "FSR",
  "registration_number": "REG-TRUCK-1",
  "registration_expiry": "2027-12-31"
}
```

Example schedule:

```json
{
  "company_id": 1,
  "vehicle_id": 1,
  "driver_id": 1,
  "gate_id": 1,
  "lane_id": 1,
  "direction": "entry",
  "scheduled_at": "2026-12-01T09:30",
  "purpose": "Delivery",
  "cargo_summary": "Textile materials"
}
```

Example approval submit and decision:

```json
{
  "request_type": "exit_clearance",
  "title": "Goods dispatch clearance",
  "note": "Dispatch documents submitted for review.",
  "company_id": 1,
  "driver_id": 1,
  "vehicle_id": 1,
  "gate_id": 1,
  "lane_id": 1,
  "direction": "exit",
  "details": {
    "destination": "Dispatch destination",
    "waybill": "WB-123"
  }
}
```

```json
{ "status": "approved", "reason": "Documents checked." }
```

Request types are `access`, `exit_clearance`, `vehicle_registration`, and
`schedule_change`. Access requests require a gate and direction; exit clearance
requires an exit-capable gate; schedule-change requests require an existing
scheduled record; vehicle-registration requests require a company. Rejected
or denied decisions require a reason. Every new request starts `pending`;
decisions are allowed only from `pending`, and every decision/cancellation is
appended to `approval_decisions`. Approvals do not operate hardware or create
physical entry/exit records.

Server validation rejects invalid references, inactive gate/company/driver/
vehicle/lane use where relevant, incompatible company-driver-vehicle links,
unsupported gate/lane directions, duplicate normalized plates, malformed
dates, and duplicate unique identity/registration values. Invalid input
returns HTTP 422, duplicate/conflicting data HTTP 409, unauthenticated access
HTTP 401, and insufficient role/permission/gate assignment HTTP 403. Responses
never include internal exception details.

## Stage 2 verification

Verified locally with XAMPP PHP 8.1.1 and MySQL:

- The migration created all six tables, two roles, and nine baseline permissions.
- All backend PHP files passed `php -l`.
- The CLI script created an initial administrator, refused a duplicate active
  administrator, and returned HTTP 404 when requested through Apache.
- HTTP checks covered correct/incorrect and inactive-account login, session ID
  regeneration, `me`, logout, CSRF rejection, administrator user creation and
  updates, password hashing/reset, officer access denial, gate assignment and
  server-side entry/exit checks, and last-administrator protection.
- The health endpoint returned HTTP 200 with PHP, MySQL server, and database
  checks healthy.

Stage 2 temporary test accounts and gate data were removed after verification.
The migration's role and permission seed data remains; create the real initial
administrator with the CLI command above.

## Stage 3 verification

Verified locally with XAMPP PHP 8.1.1 and MariaDB:

- Migration `003_core_operations.sql` applied successfully to the existing
  `hipdc_gms` database. All nine operational tables (including the existing
  `gates` table) are present; the Stage 2 roles and permissions remain intact.
- Existing administrator user ID 6 was preserved. After testing, all temporary
  Stage 3 users, gate assignments, operational records, decisions, and audit
  events were removed. The operational tables are empty and ready for real data.
- All 53 PHP files in HIPDC-GMS passed `php -l`.
- The health endpoint returned HTTP 200; PHP, database-server, and database
  checks all reported `ok`.
- HTTP tests covered company, driver, vehicle, gate, and lane create/list/search/
  view/update/status operations; schedule create/list/view/update/cancel; and
  approval submission, listing, viewing, reviewer assignment, decisions,
  cancellation, and decision history.
- Validation tests covered duplicate normalized plates and unique identifiers,
  invalid foreign keys and relationships, inactive gate/lane restrictions,
  unsupported directions, conflicting status transitions, and failed-operation
  rollback. Failed operations left the relevant record counts unchanged.
- Authorization tests covered unauthenticated access, officer restrictions,
  cross-officer approval scoping, and assigned/unassigned gate and direction
  checks. All 14 tested operational list/view routes returned HTTP 401 without
  a session; invalid CSRF tokens were rejected on state-changing requests.
- A SHA-256 comparison matched all 45 pre-existing non-backend project files;
  no additional non-backend files were found. GMS-FOR-CONDO was not modified.

Schedules remain expected visits only, and approval decisions do not create
physical entry/exit events or operate gate hardware.

## Stage 4 visitor and goods workflows

After migrations 002 and 003 have been applied, apply
`database/migrations/004_visitors_and_goods.sql` once:

```cmd
"C:\Users\happy22\xampp\mysql\bin\mysql.exe" -u root hipdc_gms < "C:\Users\happy22\xampp\htdocs\HIPDC-GMS\database\migrations\004_visitors_and_goods.sql"
```

Add `-p` if MySQL requires a password. This migration is not rerunnable:
it extends the existing `approval_requests` enum and adds columns/foreign
keys. Do not rerun it on a database where it has already been applied.

### Tables and approval relationships

- `visitors` stores visitor identity/contact details, optional host company,
  host person, purpose, expected arrival/departure, optional schedule, creator,
  status, and timestamps.
- `visitor_passes` stores a unique pass reference, visitor, one permitted gate
  and direction, validity window, lifecycle fields, issuer/revoker, and a
  SHA-256 token digest. The raw QR token is never stored.
- `goods_declarations` stores direction, required company, optional vehicle,
  driver, schedule, gate/lane, purpose, submitter, submission time, and status.
- `goods_items` stores any number of declaration items with positive quantity,
  unit, optional item/serial identifiers, and remarks.
- Existing `approval_requests` is extended with the `visitor_pass` and
  `goods_declaration` request types and unique foreign-key links to their
  domain records. Existing `approval_decisions` and `audit_events` continue
  to provide decision history and audit logging; no duplicate approval or
  audit tables are created.

All visitor pass requests and all submitted goods declarations require
approval. These defaults are deliberately restrictive. A visitor pass starts
as `pending_approval`; only an approved request activates it. A goods
declaration starts as `draft`, moves to `pending` on submission, then to
`approved` or `denied` with the shared approval decision. A draft or pending
goods declaration can be cancelled; a pending cancellation also cancels its
linked approval request and appends to the existing decision history.

An approved pass is stored as `active` until revoked. Its API response/status
is reported as `expired` when its expiration time has passed; QR validation
enforces both the start and expiration times. Visitor inactivity, host-company
inactivity, revocation, a non-approved linked request, or an inactive or
direction-incompatible gate also makes validation fail. Re-activating a
visitor or company can make an otherwise unexpired, unrevoked pass usable
again.

### Permissions and scope

Migration 004 adds:

- `visitors.view`, `visitors.create`, `visitors.update`,
  `visitors.manage_status`
- `visitors.passes.view`, `visitors.passes.issue`,
  `visitors.passes.validate`, `visitors.passes.revoke`
- `goods.view`, `goods.create`, `goods.update`, `goods.submit`,
  `goods.cancel_own`

Admin receives all new permissions. Officer receives visitor view/create,
pass view/issue/validate, and goods view/create/update/submit/cancel-own.
Officers are limited to visitors and declarations they created; their goods
editing and item changes are allowed only while the declaration is a draft.
Only Admin can update/deactivate visitors or revoke passes, and only the
existing `approvals.decide` permission can approve or deny requests.

Every gate-specific request and operation requires the actor's existing
`gate.entry` or `gate.exit` permission and matching active gate assignment.
This also applies to Admin, pass listings/validation, goods operations, and
reviewer actions on gate-bound approvals. Officers cannot read another
Officer's visitor or goods records. Lists are paginated (`page`, `per_page`,
maximum 100) and accept `q` and status filters.

### Visitor and pass endpoints

All paths are relative to `/HIPDC-GMS/`. Mutations use JSON `POST`, the
session cookie, and `X-CSRF-Token`.

| Endpoint | Permission | Purpose |
| --- | --- | --- |
| `GET/POST api/visitors/index.php` | `visitors.view` / `visitors.create` | Search/list or register a visitor. |
| `GET api/visitors/view.php?id={id}` | `visitors.view` | Read a visitor record. |
| `GET api/visitors/history.php?id={id}` | `visitors.view` | Read audit events and paginated pass/approval history. |
| `POST api/visitors/update.php?id={id}` | `visitors.update` | Update visitor details. |
| `POST api/visitors/status.php?id={id}` | `visitors.manage_status` | Set visitor status to `active` or `inactive`. |
| `GET api/visitors/passes/index.php` | `visitors.passes.view` | List/search passes; optional `visitor_id` or status filter. |
| `GET api/visitors/passes/view.php?id={id}` | `visitors.passes.view` | Read pass details and linked approval history. |
| `POST api/visitors/passes/issue.php` | `visitors.passes.issue` | Request a pass; creates a pending shared approval request. |
| `POST api/visitors/passes/validate.php` | `visitors.passes.validate` | Validate a QR token at the specified assigned gate/direction. |
| `POST api/visitors/passes/revoke.php?id={id}` | `visitors.passes.revoke` | Revoke an active pass or cancel its pending request. |

Visitor creation requires `full_name`, `purpose`, and
`expected_arrival_at` (`YYYY-MM-DDTHH:mm` or seconds); document reference,
phone, email, organization, host company/person, departure time, and schedule
are optional. If supplied, departure must be later than arrival and the linked
schedule must still be scheduled.

Pass request example:

```json
{
  "visitor_id": 12,
  "gate_id": 3,
  "direction": "entry",
  "valid_from": "2026-12-01T09:00",
  "expires_at": "2026-12-01T17:00",
  "note": "Visitor access request"
}
```

The issue endpoint returns a `pending_approval` pass and linked request, but
**no token**. An Admin decision through the existing
`POST api/approvals/decision.php?id={approval_id}` endpoint activates the
pass and returns its raw `qr_token` once in that successful approval response.
Subsequent pass/list/approval responses never include the token or its stored
hash. The token is 32 cryptographically random bytes encoded as lowercase
hexadecimal; only its binary SHA-256 digest is stored.

Validate example:

```json
{
  "qr_token": "<64-character token>",
  "gate_id": 3,
  "direction": "entry"
}
```

Validation returns `{"valid":true,...}` for a currently valid pass and
`{"valid":false}` for an unknown, not-yet-valid, expired, revoked, cancelled,
denied, inactive-visitor/company, or mismatched-gate/direction pass. It does
not consume the token, write a gate event, or prove physical entry/exit.

### Goods declaration endpoints

| Endpoint | Permission | Purpose |
| --- | --- | --- |
| `GET/POST api/goods/index.php` | `goods.view` / `goods.create` | Search/list or create a draft with multiple items. |
| `GET api/goods/view.php?id={id}` | `goods.view` | Read declaration, items, and linked approval history. |
| `GET api/goods/history.php?id={id}` | `goods.view` | Read audit and approval decision history. |
| `POST api/goods/update.php?id={id}` | `goods.update` | Update draft declaration metadata. |
| `GET/POST api/goods/items/index.php?declaration_id={id}` | `goods.view` / `goods.update` | List items or add one to a draft. |
| `POST api/goods/items/update.php?id={item_id}` | `goods.update` | Update an item on a draft. |
| `POST api/goods/items/delete.php?id={item_id}` | `goods.update` | Remove an item from a draft; send `{}` as JSON. |
| `POST api/goods/submit.php?id={id}` | `goods.submit` | Submit a non-empty draft for approval. |
| `POST api/goods/cancel.php?id={id}` | `goods.cancel_own` | Cancel own draft/pending declaration and linked request. |

Creation example:

```json
{
  "direction": "entry",
  "company_id": 4,
  "vehicle_id": 8,
  "driver_id": 5,
  "schedule_id": 10,
  "gate_id": 3,
  "lane_id": 2,
  "purpose": "Raw material delivery",
  "items": [
    {
      "description": "Packaging resin",
      "item_code": "RES-22",
      "quantity": 12.5,
      "unit": "tonne",
      "serial_number": null,
      "remarks": "Palletized"
    },
    {
      "description": "Pallets",
      "quantity": 40,
      "unit": "piece"
    }
  ]
}
```

Company and purpose are required; vehicle, driver, schedule, gate, and lane
are optional. When a schedule is supplied, company/vehicle/driver/direction
must agree, and any gate/lane on the schedule must also be supplied and match.
Quantities must be positive with up to three decimal places; declarations
support 1–100 items. Related company/vehicle/driver/schedule/gate/lane records
must exist and be active where applicable.

Submitting creates one linked pending request in the shared approval tables.
Only that request's authorized Admin decision updates the declaration to
`approved` or `denied`. Decision reasons and cancellation/decision events are
available through declaration history. Neither approval nor a scheduled visit
records physical goods movement.

### Stage 4 verification

Migration 004 was applied once to the existing `hipdc_gms` database. The four
Stage 4 tables are present. After testing, all temporary records were removed;
the original administrator (user ID 6), two roles, and existing role grants
remain. The temporary rollback-test trigger was also removed.

Verification performed:

- `php -l` passed for all 73 PHP files.
- `api/health.php` returned HTTP 200; PHP, database-server, and database checks
  all reported `ok`.
- Exercised visitor creation, listing/search, view, history, update, status
  changes, and ownership restrictions.
- Exercised pass request, approval/denial/cancellation, one-time token return,
  valid and invalid token checks, validity-window boundaries, visitor/company
  inactivity, gate/direction/assignment restrictions, and revocation. Token
  hashes are stored, not returned; validation does not create physical gate
  events.
- Exercised goods declarations with multiple items, list/search/view, draft
  updates and item add/edit/remove, submission, approval/denial/cancellation,
  history, invalid references, and restrictions on editing after submission.
- Exercised unauthenticated/unauthorized access and invalid CSRF requests.
- A temporary database trigger forced a related goods-item insert to fail;
  the API returned a generic error and the declaration, items, and audit writes
  rolled back. The trigger was removed afterward.
- Post-cleanup database checks found no Stage 4 test records and preserved the
  existing administrator and role data.
- SHA-256 comparison against the pre-Stage-4 inventory found only the five
  intended backend files modified. All 21 added files are backend files; no
  frontend files were modified.
- All 66 files in the read-only GMS-FOR-CONDO inventory matched their original
  hashes, with no files added or missing.

These tests do not represent integration with QR scanning hardware, cameras,
or physical gate controls. QR validation is reusable during a pass's validity
window and is not an entry/exit event.

## Stage 5 gate operations and access events

After migrations 002–004 have been applied, apply
`database/migrations/005_gate_operations.sql` once:

```cmd
"C:\Users\happy22\xampp\mysql\bin\mysql.exe" -u root hipdc_gms < "C:\Users\happy22\xampp\htdocs\HIPDC-GMS\database\migrations\005_gate_operations.sql"
```

Add `-p` if MySQL requires a password. Do not rerun this migration; it creates
the access-event and device tables and adds role permissions.

### Event records and lifecycle

- `access_events` is an append-only API history of granted and denied gate
  attempts. It references the existing gates, lanes, users, vehicles,
  drivers, visitors/passes, schedules, goods declarations, approvals,
  companies, and registered devices. Historical events have no update or
  delete endpoint.
- Each event includes a generated reference, direction, result and denial
  reason, gate/lane, relevant subject links, actor/device, observed plate,
  method (`manual`, `qr`, or `anpr`), timestamp, and optional audited
  override reason.
- `Idempotency-Key` is required for every access attempt. A retry with the
  same key and identical request returns the original event; using that key
  with different content returns HTTP 409. The uniqueness constraint also
  prevents duplicate device sequence submissions.
- Rejections caused by a valid gate/lane or a policy check are recorded as
  denied events. Malformed requests and nonexistent gate/lane IDs cannot be
  recorded because they cannot be reliably attached to valid foreign keys.
- Event, audit, and device sequence changes commit in one transaction. If
  audit/event persistence fails, the transaction rolls back.
- Vehicle current presence is derived only from the most recent **granted
  vehicle** event. Goods movement is deliberately separate and does not
  change vehicle presence. Visitor presence is derived from the most recent
  granted visitor event.

### Authorization policies

Every manual attempt requires an active authenticated user with
`gate_operations.process`, `gate.entry`/`gate.exit`, and a matching active
gate assignment. Gate status, supported direction, lane parent, lane status,
and lane direction are checked again server-side. Admins also need an
assignment. Officers cannot select a client-provided role or override these
checks.

- **Vehicle entry:** normalized plate must match an active registered
  vehicle; its company must be active; a supplied/assigned driver must be
  active and compatible. Entry requires an approved, unused `access`
  approval for the selected vehicle/gate/direction. If linked to a schedule,
  the schedule must remain scheduled, match vehicle/driver/company/gate/lane,
  and be for the current park-local date. An unlinked access approval is
  limited to the approval's creation date. A vehicle cannot enter when its
  most recent granted vehicle event is entry.
- **Vehicle exit:** the vehicle, company, driver, gate/lane, and active
  presence checks apply. No separate `exit_clearance` approval is required
  for an otherwise valid vehicle exit. If there is no matching entry, only
  an Admin with `gate_operations.override` can proceed, with a mandatory
  reason. The reason is stored on the event and in the audit metadata.
- **Visitor entry/exit:** submit the raw QR token to the processing endpoint;
  its SHA-256 digest is matched against the stored digest. The active approved
  pass, visitor and host-company status, validity and registered visit
  windows, gate/direction, and linked schedule date are verified. The latest
  granted visitor event prevents duplicate entry and an exit without a
  matching entry. As with Stage 4, validation alone does not consume the QR
  token. A still-valid pass may be used for re-entry after a recorded exit;
  it cannot create a second entry while the visitor is inside.
- **Goods movement:** the declaration must be approved and its shared
  approval must remain approved. Direction, company, vehicle/driver status,
  optional schedule window, and any gate/lane restrictions are verified.
  One approved declaration can create at most one granted movement event.
  An associated vehicle is not removed from or added to current vehicle
  presence by a goods event.

Vehicle schedule/date checks use MariaDB/MySQL `CURRENT_DATE` and park-local
`DATETIME` values, matching the existing schedule convention. Event
`TIMESTAMP`s use the database server timezone.

### Permissions and endpoints

Migration 005 adds `gate_operations.view`, `gate_operations.process`,
`gate_operations.override`, and `gate_devices.manage`. Admin receives all
four. Officer receives view/process only. Device management and unmatched
vehicle-exit overrides are Admin-only, and additionally require an assignment
to the relevant gate direction. Event listing/view, lookups, arrivals,
presence, summaries, and device listings are scoped to active gate
assignments and direction permissions.

Mutations use JSON `POST` with the session cookie and `X-CSRF-Token`.
`Idempotency-Key` is an additional required request header for all three
manual processing endpoints.

| Endpoint | Permission/authentication | Purpose |
| --- | --- | --- |
| `GET api/gate-operations/gates.php` | `gate_operations.view` | List assigned active gates and compatible active lanes/directions. |
| `GET api/gate-operations/arrivals.php?gate_id={id}&direction=entry` | `gate_operations.view` + assignment | List scheduled vehicle arrivals for the current park-local date. |
| `GET api/gate-operations/lookup.php?type=vehicle&q={plate}&gate_id={id}&direction=entry` | `gate_operations.view` + assignment | Search active vehicles, drivers, approved valid visitors/passes, or approved uncompleted goods declarations. |
| `POST api/gate-operations/vehicle.php` | `gate_operations.process` + assigned gate/direction + CSRF | Process a manual vehicle attempt. |
| `POST api/gate-operations/visitor.php` | `gate_operations.process` + assigned gate/direction + CSRF | Process a manual visitor pass entry/exit. |
| `POST api/gate-operations/goods.php` | `gate_operations.process` + assigned gate/direction + CSRF | Confirm an approved goods movement. |
| `GET api/gate-operations/events/index.php` | `gate_operations.view` + event gate/direction scope | Paginated history; filters include `q`, `gate_id`, `lane_id`, `vehicle_id`, `visitor_id`, `driver_id`, `company_id`, `direction`, `result`, `method`, `from`, and `to`. |
| `GET api/gate-operations/events/view.php?id={id}` | `gate_operations.view` + event gate/direction scope | View one immutable event. |
| `GET api/gate-operations/visitors/history.php?visitor_id={id}` | `gate_operations.view` + event gate/direction scope | Paginated event history for a visitor. |
| `GET api/gate-operations/presence/vehicles.php` | `gate_operations.view` + active assignment | Current vehicle presence derived from granted vehicle events. |
| `GET api/gate-operations/presence/visitors.php` | `gate_operations.view` + active assignment | Current visitor presence derived from granted visitor events. |
| `GET api/gate-operations/summary.php?from=YYYY-MM-DD&to=YYYY-MM-DD` | `gate_operations.view` + active assignment | Direction/result/method activity counts over at most 32 calendar days. |
| `GET/POST api/gate-devices/index.php` | Admin, `gate_devices.manage`; assignment for gate/direction | List assigned devices or register a device. Raw device credential is returned once at creation. |
| `POST api/gate-devices/status.php?id={id}` | Admin, `gate_devices.manage` + assignment + CSRF | Activate/deactivate a device. |
| `POST api/gate-operations/device-event.php` | Registered active QR/ANPR device credential | Authenticated QR/ANPR callback; not browser-session/CSRF based. |

Manual vehicle entry example:

```http
POST /HIPDC-GMS/api/gate-operations/vehicle.php
Idempotency-Key: park-terminal-20261114-000001
X-CSRF-Token: <session-bound-token>
Content-Type: application/json
```

```json
{
  "gate_id": 3,
  "lane_id": 7,
  "direction": "entry",
  "plate_number": "SNNP 3-A48217",
  "driver_id": 5,
  "schedule_id": 10
}
```

Visitor processing accepts `qr_token`, `gate_id`, `lane_id`, and `direction`.
Goods processing accepts `goods_reference`, `gate_id`, `lane_id`, and
`direction`. A controlled unmatched vehicle exit additionally requires
`override_reason`.

The endpoint returns a saved event in `data.event`, whose `access_result` is
`granted` or `denied`; a denied policy result is still a successfully recorded
attempt. A repeated identical idempotent request returns the same event with
`data.replayed: true`. No API response represents a physical barrier opening.

### Device authentication and hardware boundary

Admin registration returns a random `public_id.secret` credential once. The
database stores only the SHA-256 digest of the secret. Configure the full
credential in the trusted device's secret store and send it as
`X-Device-Credential`; never put it in a browser, URL, or source repository.
Callbacks must also send a positive, strictly increasing `X-Device-Sequence`
and an `Idempotency-Key`. Device type and active gate/lane/direction are bound
at registration. The sequence is locked and advanced transactionally; stale
or replayed sequences are rejected.

`QrScannerAdapter`, `AnprAdapter`, `CameraAdapter`, and
`GateControllerAdapter` in `includes/gate_device_adapters.php` are integration
contracts only. The callback endpoint currently accepts configured QR scanner
and ANPR devices. Camera capture metadata and gate-controller commands have
no configured implementation. `DisabledGateController` explicitly reports
`command_sent: false`; no API sends a physical command or reports a barrier as
open. Protect credentials with HTTPS and a device-side secret store; rotate
them by deactivating the old device and registering a replacement. Device
callback IP allow-lists, vendor protocols, key rotation tooling, and device
clock/timestamp verification are not implemented.

### Stage 5 verification

Migration 005 was applied once to the existing `hipdc_gms` database. The
`gate_devices` and `access_events` tables and four new permissions were
verified present. All 90 PHP files passed `php -l`; the health endpoint
returned HTTP 200 with PHP and database checks healthy.

HTTP tests using temporary users and operational records exercised:

- Vehicle entry requiring an approved access request and same-day valid
  schedule; successful exit; rejection of a repeated/unapproved entry,
  expired schedule, inactive company/vehicle/driver, unknown plate, and
  unmatched exit without override.
- Admin unmatched-exit override with mandatory reason; officer override
  rejection even when the Officer had both-direction gate assignment.
- Two QR validations remained non-consuming; visitor entry, duplicate-entry
  denial, exit and subsequent re-entry, revoked/expired/unknown token denial,
  and inactive-visitor exit denial.
- Multi-item goods declaration edits before submission; pending/non-approved
  declaration rejection; approved movement and duplicate completion
  rejection; completed declaration removal from lookup.
- Inactive gate/lane, mismatched lane direction, unassigned gate, and
  officer-direction restrictions were enforced. Policy denials at an assigned
  gate were recorded; attempts from an unassigned user were rejected with
  HTTP 403 rather than allowing event injection.
- Same-key retries returned the same event; reusing a key with changed valid
  content returned HTTP 409. Concurrent ANPR entry callbacks produced at most
  one grant; the competing stale device sequence returned HTTP 409.
- ANPR device registration, one-time credential handling, successful
  callback, exact retry replay, stale sequence and unconfigured credential
  rejection, inactive-device rejection, camera callback rejection, and
  administrator-only device management. Device secrets were not returned in
  device listings and a credential digest check matched the stored secret
  hash.
- Invalid CSRF returned HTTP 403; unauthenticated history returned HTTP 401;
  event deletion requests returned HTTP 405. A forced audit insert failure
  returned HTTP 500 and rolled back the event; its temporary trigger was
  removed.
- Event listing/detail, expected-arrival, entity lookup, presence, visitor
  history, and activity summary routes.

No camera, QR scanner, ANPR, or physical controller was connected. The
ANPR/QR callback behavior was exercised using registered test-device
credentials only; the physical gate-controller adapter remains disabled.

## Stage 6 supporting modules

After migrations 002–005 have been applied, apply
`database/migrations/006_supporting_backend_modules.sql` once:

```cmd
"C:\Users\happy22\xampp\mysql\bin\mysql.exe" -u root hipdc_gms < "C:\Users\happy22\xampp\htdocs\HIPDC-GMS\database\migrations\006_supporting_backend_modules.sql"
```

Add `-p` if MySQL requires a password. Do not rerun migrations 002–005. Stage
6 adds no frontend files and creates no duplicate gate, approval, or audit
tables.

### Tables and permission assignments

- `officer_shifts` stores an Officer, existing gate and optional lane,
  direction, time window, status, assigning Admin, notes, and timestamps.
  Active assignments must already authorize the Officer at that gate and
  direction. Scheduled shifts may not overlap for the same Officer or for the
  same gate/lane/direction. Cancelled shifts are retained.
- `cameras` stores inventory and manually entered monitoring metadata tied to
  the existing gate and optional lane. Configuration accepts only
  `resolution`, `frame_rate`, and `view_description`; stream URLs and access
  credentials are rejected. This is not a video service or hardware
  integration.
- `notifications` stores user-specific messages and read timestamps. Users
  may view and mark only their own notifications; only Admin may create a
  notification for an active account.
- Audit browsing reads the existing `audit_events` table. There is no update
  or delete API for audit records.

Migration 006 adds `dashboard.view`, `shifts.view`, `shifts.manage`,
`cameras.view`, `cameras.manage`, `reports.view`, `notifications.view`,
`notifications.read`, `notifications.manage`, and `audit.view`. Admin
receives all ten. Officer receives dashboard, shift/camera viewing, reports,
and own-notification read permissions. Officer shift and camera visibility is
limited to own shifts and gates assigned to that Officer. Shift assignment,
camera configuration, notification creation, and audit-log viewing are
Admin-only.

### Endpoints

All paths are relative to `/HIPDC-GMS/`. List endpoints use `page` (default
1) and `per_page` (default 25, maximum 100). State-changing browser requests
use JSON `POST`, the session cookie, and `X-CSRF-Token`.

| Endpoint | Permission | Purpose |
| --- | --- | --- |
| `GET api/dashboard/index.php` | `dashboard.view` | Role-scoped last-24-hour vehicle, visitor, and gate-event activity; presence, pending approvals, today's schedules, current shifts, and camera inventory counts. |
| `GET/POST api/shifts/index.php` | `shifts.view` / Admin + `shifts.manage` | List/search shifts or assign an Officer. |
| `GET api/shifts/view.php?id={id}` | `shifts.view` | Read a shift; Officers may view only their own. |
| `POST api/shifts/update.php` | Admin + `shifts.manage` | Update a scheduled shift; JSON body includes `id` and shift fields. |
| `POST api/shifts/cancel.php` | Admin + `shifts.manage` | Cancel a scheduled shift; JSON body includes `id`. |
| `GET/POST api/cameras/index.php` | `cameras.view` / Admin + `cameras.manage` | List/search inventory or register camera metadata. |
| `GET api/cameras/view.php?id={id}` | `cameras.view` | Read metadata within assigned gate scope. |
| `POST api/cameras/update.php` | Admin + `cameras.manage` | Update inventory metadata; JSON body includes `id`. |
| `POST api/cameras/status.php` | Admin + `cameras.manage` | Set status to `active`, `inactive`, or `maintenance`. |
| `POST api/cameras/monitoring.php` | Admin + `cameras.manage` | Record `unknown`, `healthy`, `degraded`, or `offline` monitoring metadata. |
| `GET api/reports/index.php?report={type}` | `reports.view` | Paginated filtered vehicle movements, visitor activity, goods declarations, approvals, or gate events. |
| `GET api/reports/index.php?report={type}&format=csv` | `reports.view` | Download a filtered CSV (maximum 10,000 rows; spreadsheet formula prefixes are neutralized). |
| `GET/POST api/notifications/index.php` | `notifications.view` / Admin + `notifications.manage` | List the signed-in user's messages or create one for an active target user. |
| `POST api/notifications/read.php` | `notifications.read` | Mark the signed-in user's notification read; body `{ "id": 123 }`. |
| `POST api/notifications/read-all.php` | `notifications.read` | Mark all unread notifications for the signed-in user read; body `{}`. |
| `GET api/audit/index.php` | Admin + `audit.view` | Read-only paginated audit events; filters: `actor_id`, `action`, `entity_type`, `from`, and `to`. |

Shift creation example:

```json
{
  "officer_id": 14,
  "gate_id": 3,
  "lane_id": 2,
  "direction": "entry",
  "starts_at": "2026-12-01T08:00",
  "ends_at": "2026-12-01T16:00",
  "notes": "Day shift"
}
```

Camera metadata example:

```json
{
  "name": "North Gate Overview",
  "model": "Model reference",
  "serial_reference": "CAM-010",
  "location": "North entrance",
  "gate_id": 3,
  "lane_id": 2,
  "configuration": {
    "resolution": "1920x1080",
    "frame_rate": 30,
    "view_description": "Metadata only; no live feed."
  }
}
```

Report types are `vehicle_movements`, `visitor_activity`,
`goods_declarations`, `approvals`, and `gate_events`. Reports accept optional
`from`/`to` dates (at most 366 calendar days when both are supplied),
`gate_id`, `company_id`, and `direction`; goods and approval reports also
accept a supported `status`. Event data is scoped using
the existing gate assignment and direction permissions. Officer goods and
approval reports are further limited to their own submissions. Gate-bound
goods and approval reports for both roles require an active assigned gate and
direction. These are history/report endpoints: they do not issue access,
approve declarations, or control gates.

Notification creation example:

```json
{
  "user_id": 14,
  "notification_type": "administrative",
  "title": "Shift schedule updated",
  "message": "Your assigned shift schedule has changed."
}
```

Successful notification creation returns `data.notification`; list and
mutation responses use the standard JSON response envelope and never return
another user's notification content.

Dashboard presence is derived from the latest granted vehicle/visitor event;
it is not a live sensor reading. Camera health is manually reported metadata.
Current shift information describes scheduled assignments, not officer
presence. Audit records remain append-only and this stage exposes only GET.

### Stage 6 verification

Migration 006 was applied once to the existing `hipdc_gms` database. The
`officer_shifts`, `cameras`, and `notifications` tables and all ten new
permissions were verified. The original user, two roles, existing operational
tables, and their data were preserved; temporary test users, gates, shifts,
cameras, notifications, and audit entries were removed after testing.

Verification performed:

- All 16 Stage 6 PHP files passed `php -l`.
- `api/health.php` returned HTTP 200 with PHP, MariaDB server, and database
  checks healthy.
- Dashboard and all five report types returned successfully for Admin and
  Officer contexts; report date/gate/direction/status filters and CSV
  generation were exercised. An empty CSV still includes its column header.
- Unauthenticated dashboard, shift, camera, report, notification, and audit
  requests returned HTTP 401. Officer restrictions on shift assignment,
  notification creation, audit viewing, and unassigned-gate camera viewing
  returned HTTP 403. Invalid CSRF returned HTTP 403 and invalid camera
  configuration, gate assignment, and report date ranges returned HTTP 422.
- Shift create/list/view/update/cancel, overlap rejection (HTTP 409), and
  cancelled-shift update rejection were exercised.
- Camera create/list/view/update/status/monitoring metadata and restricted
  configuration were exercised. Camera monitoring values remain manually
  reported and do not indicate a hardware connection.
- Notification create/list/read/read-all and owner-only access were exercised.
  Audit records were readable by Admin and audit mutation returned HTTP 405.
- A temporary database trigger forced camera audit logging to fail; the API
  returned HTTP 500 and the camera insert rolled back. The trigger and test
  data were removed.
- SHA-256 comparison found only `api/README.md` changed among the 142
  pre-existing HIPDC files; all 17 added files are backend-only. All 66
  pre-existing GMS-FOR-CONDO files matched their baseline with no additions
  or removals.

Stage 6 does not provide live video, camera hardware monitoring, physical
gate control, or proof of staff presence.
