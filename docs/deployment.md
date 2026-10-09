# Deployment

Two deployables share one database and one `.env`:

1. **The ERP app** (this project) — secret-link login at `/login/<LOGIN_SECRET_PATH>`.
2. The **public website** — the `(public)` routes in the same Next.js build
   (`/`, `/about`, `/services`, `/doctors`, `/book`, `/contact`, `/faq`, `/privacy`).

## Prerequisites

- Node.js 20+ and npm.
- PostgreSQL 16+ reachable via `DATABASE_URL`.
- A reverse proxy/TLS terminator (Caddy, nginx, or a platform LB).
- PostgreSQL client tools (`pg_dump`/`pg_restore`/`psql`) for backups.

## 1. Configure

```bash
cp .env.example .env
```

Fill, at minimum:

| Key | Notes |
| --- | --- |
| `DATABASE_URL` | production Postgres; add `?sslmode=require` when required |
| `AUTH_SECRET` | `openssl rand -base64 32` |
| `LOGIN_SECRET_PATH` | `openssl rand -hex 12` — treat as a secret |
| `OWNER_EMAIL` / `OWNER_PASSWORD` | first owner account; password ≥ 10 chars |
| `RECEPTION_EMAIL` / `RECEPTION_PASSWORD` | created with forced password change |
| `STAFF_EMAIL` / `STAFF_PASSWORD` | created with forced password change |
| `UPLOAD_DIR` | persistent volume path (e.g. `/var/lib/vision-erp/uploads`) |
| `PUBLIC_BASE_URL` | canonical https origin (SEO/hreflang/sitemap) |
| `CRON_SECRET` | ≥ 16 chars to enable `POST /api/jobs/messages` |

Everything is validated at boot (`src/env.ts`) — the app refuses to start on a
bad/missing value and never logs the values.

## 2. Build & migrate

```bash
npm ci
npx prisma generate
npm run db:deploy      # prisma migrate deploy (applies committed migrations)
npm run db:seed        # idempotent: settings, staff, users, tags, demo data
npm run build
npm start              # or a process manager (systemd/pm2) on :3000
```

> `npm run db:seed` also inserts demo patients/sales/HR/appointments. On a
> clean production database run it once to create the owner + settings; remove
> or guard the demo sections if you do not want sample data.

## 3. Reverse proxy

Terminate TLS and forward to `127.0.0.1:3000`. Example (Caddy):

```
erp.visioneye.et {
  reverse_proxy 127.0.0.1:3000
  encode zstd gzip
}
```

The app already sets CSP, `X-Frame-Options: DENY`, `nosniff`, HSTS (prod),
Referrer-Policy and Permissions-Policy (`next.config.ts`). Do not weaken CSP.

## 4. Scheduled jobs

Messages no longer require a server (manual-link mode), but the daily job that
expires/creates tasks and the optional daily summary email should run:

```cron
*/15 * * * * curl -fsS -X POST -H "Authorization: Bearer $CRON_SECRET" https://erp.visioneye.et/api/jobs/messages
```

## 5. Health & smoke

```bash
curl -fsS http://127.0.0.1:3000/login/<LOGIN_SECRET_PATH> >/dev/null
bash scripts/smoke.sh                       # 173 checks against a running server
npm run security:scan                       # regenerates docs/security-review.md
```

## Upgrades

```bash
npm run backup                    # always before deploying
git pull
npm ci
npm run db:deploy                 # new migrations
npm run build
# restart the service
```

`prisma migrate deploy` is forward-only; never edit applied migrations.

## Rollback

Restore the pre-deploy dump (`npm run restore -- <file>`), check out the
previous release and `npm run build`, restart. Keep the last known-good release
directory on disk for instant switch-back.
