# Vision Eye Clinic ERP

Single-tenant clinic ERP for Vision Eye Clinic: a **bilingual public website**
(marketing pages, booking, live "on duty" doctors, CMS-lite, SEO) plus lite
clinic management (users, staff, settings, audit trail, patients, services,
sales/POS + revenue, HR lite — shifts/schedule, leave approvals — and
appointments + follow-up recalls).

The ERP is reachable **only** through the secret link
`/login/<LOGIN_SECRET_PATH>`; every other `/login/...` path is a hard 404.

## Stack

- **Laravel 13** on **PHP 8.3+** (Blade views, no server-side JS framework)
- **MySQL 8.0** (the local dev alternative is SQLite)
- Session auth with **argon2id** password hashing, 12-hour sessions
- **en / am** — every UI string is translated (Laravel `lang/` catalogues)
- PHPUnit 12 (unit + feature tests), Laravel Pint (style)
- Tailwind CSS, compiled to `public/build/` and **committed** — neither the host nor a fresh checkout needs Node.js

## Quick start (local)

```bash
composer install
cp .env.example .env
php artisan key:generate

# SQLite (no MySQL needed): set DB_CONNECTION=sqlite and
# DB_DATABASE=/absolute/path/to/database/database.sqlite
php artisan migrate
php artisan db:seed          # settings + doctor + 6 services + 3 accounts (no demo data)

php artisan serve            # http://localhost:127.0.0.1:8000
```

Login URL: `http://localhost:8000/login/<LOGIN_SECRET_PATH>` — any other
`/login/...` path returns a real 404.

Seeded accounts (passwords come from `.env`):

| Role      | Env                 | First login          |
| --------- | ------------------- | -------------------- |
| OWNER     | `OWNER_EMAIL`       | not forced (seed)    |
| RECEPTION | `RECEPTION_EMAIL`   | not forced (seed)    |
| STAFF     | `STAFF_EMAIL`       | not forced (seed)    |

New accounts created in the app are forced to change their password at first
sign-in.

## Commands

| Command | What it does |
| --- | --- |
| `php artisan test` | The whole suite (940 tests). |
| `php artisan test --testsuite=Unit` | The pure-logic tests only. |
| `./vendor/bin/pint` | Formats the code (run before committing). |
| `./vendor/bin/pint --test` | Checks the formatting without changing anything. |
| `php artisan migrate --force` | Applies the migrations. |
| `php artisan db:seed --force` | The production seed (idempotent). |
| `php artisan schedule:run` | The scheduler — expires the message tasks. |
| `php artisan messages:run-jobs` | The same job, run by hand. |
| `php scripts/convert-messages.php` | Regenerates `lang/` from `messages/*.json`. |
| `bash scripts/preflight.sh` | The host-side pre-flight check: PHP, `.env`, the database, the migrations, the seed, the permissions, the scheduler. |
| `php scripts/env-set.php <KEY>` | Writes a value into the `.env` with a hidden prompt and the quoting Dotenv needs (a password with a space or `#` breaks the file otherwise). |
| `bash scripts/smoke.sh` | The production smoke test against a running site. |
| `php scripts/php-version-check.php 8.3` | Parses every PHP file **and every compiled Blade template** against the PHP 8.3 grammar. |
| `bash scripts/backup.sh` | A gzip-compressed `mysqldump` of the database. |

## Documentation

| Document | What it covers |
| --- | --- |
| **[docs/PLESK_DEPLOYMENT.md](docs/PLESK_DEPLOYMENT.md)** | **The deployment guide** — Plesk, MySQL 8.0, no Node on the host, the cron, the backups and the go-live checklist. |
| [docs/go-live.md](docs/go-live.md) | The go-live checklist (short form). |
| [docs/backup-restore.md](docs/backup-restore.md) | What to back up, how, and how to restore. |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Common problems and their fixes. |
| [docs/roles.md](docs/roles.md) | Who may see and do what. |
| [docs/security-review.md](docs/security-review.md) | The security model and the threat surface. |
| [docs/known-gaps.md](docs/known-gaps.md) | The deliberate limitations of this release. |
| [docs/uat.md](docs/uat.md) | The manual acceptance checklist. |
| [docs/messaging-6b-manual.md](docs/messaging-6b-manual.md) | The campaign/audience manual. |
| [docs/spec/](docs/spec/) | The per-slice rebuild specifications (the porting contract). |

## Project structure

```
app/
  Console/Commands/   the scheduled message job
  Http/Controllers/   one controller per surface
  Http/Middleware/    the role gate, the forced password change, the headers
  Models/             Eloquent models (ULID primary keys)
  Support/            the pure logic (money, datetime, roles, filters, …)
    Appointments/     the status graph, the coverage advisories, the presets
    Hr/               the shift/leave geometry, the duty projection
    Messaging/        the templates, the schedule math, the filters
    Patients/         the patient queries, the CSV/import helpers
    PublicSite/       the CMS content, the public projections, the SEO
    Sales/            the sale lines, the receipt numbering, the revenue
    Services/         the service catalogue helpers
    Shell/            the user/staff form validation
database/
  migrations/         the schema (MySQL 8.0, portable to SQLite for the tests)
  seeders/            the production seed (no demo data)
lang/{en,am}/         the compiled translation catalogues
messages/{en,am}.json the translation SOURCE (edit here, then convert)
public/build/         the compiled CSS/JS (committed — the host needs no Node)
resources/views/      the Blade views
routes/web.php        the public + ERP routes
routes/api.php        the session-free API routes
scripts/              the smoke test, the backup/restore, the message converter
tests/                the test suite (Unit + Feature)
```

## The public website

`/`, `/about`, `/services`, `/doctors`, `/book`, `/contact`, `/faq`,
`/privacy` — no session, **no prices and no patient data**, ever. The content
is editable by the OWNER under **Website content** (`/dashboard/content`).

The public booking form (`/book`) posts to `POST /api/public/requests` and
creates a **CONFIRMED** appointment immediately (the doctor is assigned
server-side) — there is no request queue to clear.

## The messaging model — manual send only

The app **never transmits anything**. It prepares an `sms:` link (Android
`?body=`, iOS `&body=`) that the operator opens on the clinic phone, then
confirms "sent" in the app. Group ("one group chat") delivery splits the
recipients into batches that the operator sends one by one. See
[docs/messaging-6b-manual.md](docs/messaging-6b-manual.md).

## Security model

- **The only entry point** is `/login/<LOGIN_SECRET_PATH>`. A wrong secret is a
  real 404 (no redirect, no hint).
- **Server-side authorization is the boundary.** `OWNER` / `RECEPTION` /
  `STAFF`; every route and every action checks the role. The UI hides what a
  role cannot do, but the server is what enforces it.
- **No hard deletes.** Users, staff, patients, services and shifts are only
  ever deactivated (`active = false`).
- **Every write is audited** (`audit_logs`) with the actor, the entity, the
  action and the changed fields. Secrets are redacted.
- **Money is exact**: `NUMERIC(12,2)`, never a float. Prices are snapshotted
  on the sale line, so a later price change never rewrites history.
- **Instants are stored in UTC** and displayed in the clinic timezone
  (Settings → timezone, default `Africa/Addis_Ababa`).
- **Phones are stored in E.164** (`+2519…` / `+2517…`).
- **No clinical records.** Patients hold contact/admin data only — never
  diagnoses, treatments or prescriptions.
- **The public website never leaks**: strict allow-list projections, a
  recursive forbidden-field scan in the tests, and only the sha-256 hash of an
  IP is ever stored.

## Money / time / phone conventions

- **Money** — `NUMERIC(12,2)`, 2 dp, half-up, computed with `bcmath`. A
  quantity is always a whole number. The maximum is `9999999999.99`.
- **Time** — every instant is stored in UTC; the clinic timezone (a setting)
  decides what a "day" is. The daily/weekly buckets use clinic-local days.
- **Phone** — Ethiopian mobile only: `09…`, `07…`, `+2519…`, `+2517…`
  (spaces, dashes, dots and brackets are tolerated). Everything else is
  rejected.
