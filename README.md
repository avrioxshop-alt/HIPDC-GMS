# HIPDC Gate & Logistics Management System

HIPDC-GMS is a PHP/MySQL application with an existing HTML/CSS/JavaScript
frontend. The frontend keeps the current page design and uses same-origin PHP
JSON APIs, PHP sessions, server-side role/permission checks, and CSRF tokens.
See [api/README.md](api/README.md) for backend setup, migration history, API
contracts, and security details. See
[docs/frontend-architecture.md](docs/frontend-architecture.md) for the UI
architecture and design specification.

## Requirements and local setup

- XAMPP (Apache, PHP 8.1+, MySQL/MariaDB), or an equivalent PHP/MySQL setup.
- Serve this directory through Apache; do not use `file://` or a static-only
  Live Server, because authentication and data require the PHP API.
- Configure the database environment variables and apply migrations in order
  as documented in [api/README.md](api/README.md). Create the initial admin
  using `scripts/create_admin.php`; do not commit credentials.
- Open `http://localhost/HIPDC-GMS/`. The public health check is
  `http://localhost/HIPDC-GMS/api/health.php`.

The app's base API URL is derived from `js/data/api-client.js`, so it works
when installed below the web root. Authentication uses the PHP session cookie;
the role and available gate directions come from the server, not the login
screen's visual role selector. Login/logout and other state-changing API
requests use the session-bound CSRF token.

## Frontend-to-backend integration

The detailed page/API map, shared data-flow, and limitations are in
[docs/stage7-integration-map.md](docs/stage7-integration-map.md). The principal
connections are:

| Frontend area | Backend API |
| --- | --- |
| Sign-in, session, sign-out | `api/auth/{csrf,login,me,logout}.php` |
| Admin dashboard, approvals | `api/dashboard/`, `api/approvals/` |
| Vehicle/schedule registries and gate workflows | `api/vehicles/`, `api/schedules/`, `api/gate-operations/` |
| Visitors and pass requests | `api/visitors/`, `api/approvals/` |
| Goods declarations | `api/goods/`, `api/approvals/` |
| Admin operations (companies, drivers, users, gates, shifts, camera metadata) | `api/companies/`, `api/drivers/`, `api/users/`, `api/gates/`, `api/lanes/`, `api/shifts/`, `api/cameras/` |
| Reports, notifications, audit | `api/reports/`, `api/notifications/`, `api/audit/` |

`js/data/api-client.js` is the shared API client and frontend data adapter.
The browser renders records returned by permitted endpoints; failed API
requests are surfaced as errors rather than replaced with mock records.

## Operational and hardware boundaries

- A schedule, approval, visitor pass, QR validation, or goods declaration is
  not a physical entry/exit. Gate access events are created only by authorized
  gate-operation APIs.
- Gate operations are authorization/audit records, not proof of a physical
  barrier crossing. No camera feed, ANPR, QR scanner, or barrier controller is
  connected. Camera screens manage metadata/status only.
- Goods fields that the existing UI does not represent in the backend schema
  are not persisted as separate structured fields. The integration map
  describes the current field mappings and unfinished features.
- CSV report downloads use the backend report endpoints. Existing browser PDF
  output is a printable document generated from the currently displayed event
  data; it is not a signed/authoritative backend report.

## Verification status

The authenticated Stage 7 exit goods declaration workflow has been validated
against the isolated Stage 7 database using the real Officer and Admin browser
sessions. Verified outcomes include the goods form lifecycle across pending,
approved, denied, cancelled, duplicate-submit protection, saved-draft reload,
and stale-ID cleanup. The broader Stage 7 matrix remains partial and is
recorded in the integration map; this should not be treated as a full
production sign-off for every admin/officer workflow in the project.

## Deployment checklist

1. Use HTTPS and secure production session-cookie settings.
2. Set `HIPDC_APP_ENV=production` and protected least-privilege database
   credentials; do not use local XAMPP defaults.
3. Apply each database migration once and back up the database before upgrades.
4. Create an administrator through the CLI; create real users and gate
   assignments through administration.
5. Configure login rate limiting and monitored backups/logs at the hosting
   layer.
6. Exercise Admin/Officer authorization, gate assignment/direction, CSRF,
   validation, reporting, and complete operational workflows in the deployment
   environment before launch.
