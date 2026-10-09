# Troubleshooting

First: capture what you see — the URL, the exact on-screen message, the time,
and the account role. Most problems are visible in `/audit` (OWNER) or the
server console.

## The app will not start

The process exits with `Invalid environment configuration: …`. `src/env.ts`
lists each bad/missing key. Fix `.env` and restart. Common causes:

- `AUTH_SECRET` shorter than 32 chars.
- `LOGIN_SECRET_PATH` missing, < 8 chars, or containing `/`/spaces.
- Seed passwords shorter than 10 chars.

## `DATABASE_URL` / connection errors

- Local dev: run `npm run db:up` (embedded Postgres on `:5433`). `ECONNREFUSED`
  means it is not running.
- Production: verify host/port/credentials and `?sslmode=require` if the server
  requires TLS. Check the database is reachable from the app host.
- After a schema change: `npm run db:deploy`.

## "This page does not exist" on login

`/login/…` returns a real 404 unless the path exactly equals
`LOGIN_SECRET_PATH`. Use the full secret URL. If it changed, users' bookmarks
break — update them.

## Locked out / too many attempts

Five failed logins lock the account for 15 minutes; > 30 attempts / 5 min per
IP is rate-limited. An OWNER can reset the user's password from `/users`
(sends a one-time temp password). Check `/audit` for `LOGIN_FAILED`.

## Forced back to "Change password"

Accounts created with `mustChangePassword` must change it before using any
other page. After changing, the app re-signs you in. If you are stuck in a
loop, sign out and in again; if it persists, an OWNER can toggle the flag by
resetting the password.

## Forbidden page (403) for the wrong person

Roles decide access: OWNER (everything), RECEPTION (front desk + patients,
sales, appointments, requests, messages, team schedule view), STAFF (own
schedule + leave only). Reassign the role in `/users/staff`. The same rules
apply to the export/API endpoints.

## Messages look wrong or empty

The outbox is built by the message jobs. As OWNER press **Run message jobs now**
(or wait for cron). If everything is BLOCKED, check the reason shown (no
consent, opted out, invalid phone, inactive, source cancelled). Manual-link
mode: nothing is sent automatically — the receptionist sends from the phone.

## SMS link does not open correctly

Test on real devices: Android uses `sms:+251…?body=…`, iOS uses
`sms:+251…&body=…`. If the body is missing, update the OS/app. "Copy message"
+ "Copy number" are the fallback.

## Excel/CSV export is empty or refuses

Exports honour the filters currently applied on screen. If the list shows
nothing, the export is empty. XLSX files are generated internally (no Excel
needed to create them); open them in Excel, Calc or Google Sheets.

## Backups fail

`pg_dump not found` means the PostgreSQL client tools are not installed (the
embedded dev database does not include them). Install `postgresql-client`
matching the server major version or run the dump in a Postgres container. See
`docs/backup-restore.md`.

## Slow lists / timeouts

See `docs/performance.md`. The largest boards (appointments 500, requests 200,
messages 500, leave 100 rows) are capped by design; use the filters to narrow.
Lists (patients, sales, audit) are paginated.

## Photos will not upload

Only PNG/JPEG/WebP ≤ 2 MB. Larger or other types are rejected. Ensure
`UPLOAD_DIR` exists and is writable by the app user and is a **persistent**
volume in production.

## Getting help

Provide: role, URL, on-screen message, timestamp, and the relevant `/audit`
rows. For code-level issues, run `npm run typecheck && npm run lint && npm test`
and include the output.
