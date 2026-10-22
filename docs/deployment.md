# Deployment

**The full deployment guide lives in
[PLESK_DEPLOYMENT.md](PLESK_DEPLOYMENT.md)** — Plesk, PHP 8.3+, MySQL 8.0,
no Node.js on the host, the cron entry, the backups and the go-live checklist.

This page is the short version.

## What gets deployed

| Part | Path | Notes |
| --- | --- | --- |
| Public website | `/`, `/about`, `/services`, `/doctors`, `/book`, `/contact`, `/faq`, `/privacy` | No session, no patient data, no prices. |
| The ERP | everything else | Reachable only through `/login/<LOGIN_SECRET_PATH>`. |
| JSON APIs | `/api/*` | Session-free where the contract says so. |

One Laravel application, one MySQL database, one `.env`.

## Prerequisites

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`,
  `ctype`, `json`, `fileinfo` and **bcmath** (the money helpers need it).
- MySQL 8.0.
- The document root must point at `public/`.
- One cron entry: `php artisan schedule:run` every five minutes.

## The short version

```bash
# 1. Upload the repository into /var/www/vision (document root → public/)
composer install --no-dev --optimize-autoloader

# 2. Configure
cp .env.example .env
php artisan key:generate
#   → APP_URL, DB_*, LOGIN_SECRET_PATH, the three seed accounts, PUBLIC_BASE_URL

# 3. Schema + seed (no demo data)
php artisan migrate --force
php artisan db:seed --force

# 4. Permissions
chown -R <user>:<user> storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chmod 640 .env

# 5. Cron (Plesk → Tools & Settings → Cron Jobs, every 5 minutes)
cd /var/www/vision && /usr/bin/php artisan schedule:run >> /dev/null 2>&1

# 6. HTTPS (Plesk → SSL/TLS → Let's Encrypt → force HTTPS)

# 7. Verify
BASE=https://visioneye.clinic.et bash scripts/smoke.sh
```

## Front-end assets

The CSS/JS are **committed** under `public/build/`, so the host needs no
Node.js — just upload the directory. The compiled assets are part of the
repository; a CSS change is a separate piece of work (the build tooling is
not part of this repository).

## Updating later

```bash
# on the host
php artisan migrate --force     # only when there are new migrations
php artisan optimize:clear
```
