# Deployment — Plesk shared hosting, PHP 8.3+, MySQL 8.0

**Target host:** Plesk (shared/VPS) with **PHP 8.3 or newer**, **MySQL 8.0**,
**no Node.js runtime** (the front-end assets are built locally and committed).

This document is the authoritative deployment guide for the Laravel rebuild.
It replaces the legacy `docs/deployment.md` (Next.js + Vercel + PostgreSQL).

---

## 0. What the app is

| Part | Path | Notes |
| --- | --- | --- |
| Public website | `/`, `/about`, `/services`, `/doctors`, `/book`, `/contact`, `/faq`, `/privacy` | No session, no patient data, no prices. |
| ERP | everything else | Reachable only through `/login/<LOGIN_SECRET_PATH>`. |
| JSON APIs | `/api/*` | Session-free where the contract says so; every route enforces its own check. |

**Stack:** Laravel 13 · PHP 8.3+ · MySQL 8.0 · Blade + Tailwind (compiled
CSS/JS committed under `public/build`) · session auth with argon2id.

---

## 1. Prerequisites on the host

| Requirement | Minimum | How to check |
| --- | --- | --- |
| PHP | 8.3 | `php -v` |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `bcmath` | `php -m` |
| MySQL | 8.0 | `mysql --version` |
| Composer | 2.x | `composer --version` (only needed on the host if you install dependencies there) |
| Disk | 1 GB free (app + vendor + logs + uploads) | `df -h` |
| Cron | 1 entry | Plesk → **Tools & Settings → Cron Jobs** (or the web-panel "Scheduled Tasks") |

> **bcmath is required** — the money helpers use it for exact decimal
> arithmetic. Without it the sale totals would be wrong.

**Plesk PHP settings:** set the domain's PHP version to **8.3+** and enable the
extensions above (`Domains → your-domain → PHP Settings`).

---

## 2. Create the database

In Plesk → **Databases → MySQL Databases**:

1. Name: `vision_eye_clinic`
2. User: `vision_app` with a strong random password
3. **Copy both the database name, the user and the password** — you need
   them for `.env`.

The database user needs full rights on that schema (Plesk grants them by
default).

---

## 3. Upload the application

Upload the repository **without** `vendor/`, `node_modules/` and
`storage/framework/cache` (they are created on the host):

```bash
# Locally, prepare a clean copy
git archive --format=tar HEAD | gzip > /tmp/vision-eye-clinic.tar.gz
# Upload /tmp/vision-eye-clinic.tar.gz through Plesk → File Manager,
# then extract it into the domain's document root (e.g. /var/www/vision).
```

The document root should contain `artisan`, `composer.json`, `app/`,
`bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`,
`storage/`, `tests/`, `lang/`, `messages/`, `scripts/`, `docs/`.

### The document root MUST be `public/`

Plesk → **Domains → your-domain → Document Root** → set it to
`/var/www/vision/public`. Never expose the project root: `public/` is the only
directory a web request may reach.

---

## 4. Install the dependencies

Two options — pick one.

### Option A — install on the host (needs Composer + SSH/terminal)

```bash
cd /var/www/vision
composer install --no-dev --optimize-autoloader --no-interaction
```

### Option B — install locally and upload `vendor/` (no Composer on the host)

```bash
# Locally, with the SAME PHP minor version as the host:
composer install --no-dev --optimize-autoloader
```

Then upload the `vendor/` directory together with the rest of the app.

> `composer.lock` is committed — **always** use `composer install` (never
> `composer update`) so the host runs exactly the tested dependency set.

---

## 5. Configure `.env`

```bash
cd /var/www/vision
cp .env.example .env
php artisan key:generate          # writes APP_KEY
chmod 640 .env                    # readable by the PHP user only
```

Edit `.env` and set at least:

| Key | Value | Notes |
| --- | --- | --- |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **Never** `true` in production. |
| `APP_URL` | `https://clinic.example.com` | No trailing slash. |
| `APP_KEY` | generated | `php artisan key:generate` |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` | `127.0.0.1` | On Plesk, MySQL is usually local. |
| `DB_PORT` | `3306` | |
| `DB_DATABASE` | `vision_eye_clinic` | From step 2. |
| `DB_USERNAME` | `vision_app` | From step 2. |
| `DB_PASSWORD` | *(the generated password)* | |
| `LOGIN_SECRET_PATH` | `openssl rand -hex 12` | **The staff login URL is `/login/<this>`.** At least 8 characters, `[A-Za-z0-9_-]` only. Any other `/login/...` path returns a real 404. |
| `OWNER_EMAIL` / `OWNER_PASSWORD` | real values | The seed creates this account. |
| `RECEPTION_EMAIL` / `RECEPTION_PASSWORD` | real values | |
| `STAFF_EMAIL` / `STAFF_PASSWORD` | real values | |
| `PUBLIC_BASE_URL` | `https://clinic.example.com` | Used for the canonicals, the sitemap and `robots.txt`. |
| `SESSION_SECURE_COOKIE` | `true` | Once the site is HTTPS-only. |
| `CRON_SECRET` | `openssl rand -hex 24` | Bearer token for the message job. Leave blank to disable the endpoint. |
| `STORAGE_DRIVER` | `db` | Uploads as database blobs (survives a disk wipe). Use `local` + `UPLOAD_DIR` for the filesystem instead. |

Everything else keeps its default. The full list with comments is in
`.env.example`.

> **Never commit `.env`.** It is already in `.gitignore`.

---

## 6. Create the schema and seed

```bash
cd /var/www/www/vision   # your path
php artisan migrate --force
php artisan db:seed --force
```

The production seed creates **only**:

- the 17 clinic settings,
- one doctor staff row (`Dr. Abebe Kebede`),
- the **six real services** (General Eye Care, Eye Examination for Glasses,
  Glasses Making and Sales, Special Eye Exam…, Children's Eye Exam, Minor Eye
  Surgery),
- the three accounts from `.env` (OWNER / RECEPTION / STAFF).

**No demo patients, sales, appointments or messages are created.** The seed is
idempotent: re-running it never overwrites an edited setting or a clinic-set
service price.

Check it:

```bash
php artisan tinker --execute="echo App\Models\Patient::count();"
# → 0
```

---

## 7. Permissions

```bash
cd /var/www/vision
chown -R <plesk-ftp-user>:<plesk-ftp-user> storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chmod 640 .env
```

`storage/` and `bootstrap/cache/` must be writable by the PHP process
(logs, sessions, cache, compiled views).

---

## 8. Front-end assets

The CSS/JS are **committed** under `public/build/` — upload the directory with
the rest of the app. The host needs **no Node.js** and no build step.

After any front-end change (and after the first deploy):

```bash
php artisan optimize:clear
```

---

## 9. The scheduler (cron)

Plesk → **Tools & Settings → Cron Jobs** (or **Websites → Scheduled Tasks**),
add a job that runs **every 5 minutes**:

```
cd /var/www/vision && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Adjust the PHP binary path if Plesk installs a versioned one
(`/opt/plesk/php/8.3/bin/php`). The scheduled command
(`messages:run-jobs`) expires the message tasks whose send window has passed.

**Verify it works:**

```bash
php artisan schedule:list
# →  */5 * * * * php artisan messages:run-jobs
```

---

## 10. Web-server / HTTPS

1. Plesk → **Domains → your-domain → SSL/TLS** → install the Let's Encrypt
   certificate and **force HTTPS**.
2. Keep the document root at `public/` (step 3).
3. The app ships the security headers itself (CSP, `X-Frame-Options: DENY`,
   `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS in production) —
   do not duplicate them in the web-server config.

---

## 11. Post-deploy smoke test

```bash
# Locally or on the host (the host must reach the site):
BASE=https://clinic.example.com bash scripts/smoke.sh
```

The script logs in through the secret path and checks the ERP screens, the
public pages, the exports and the API contracts. Expect a clean run.

Manual spot-checks:

| URL | Expected |
| --- | --- |
| `/` | 200, the public home page |
| `/book` | 200, the booking form |
| `/login` | **404** (never a redirect) |
| `/login/<wrong secret>` | **404** |
| `/login/<LOGIN_SECRET_PATH>` | 200, the login form |
| `/dashboard` | 302 → `/login/<LOGIN_SECRET_PATH>` when signed out |
| `/sitemap.xml` | 200, XML with 8 URLs |
| `/robots.txt` | 200, plain text |

---

## 12. Backups

**What to back up**

| Item | Where | How |
| --- | --- | --- |
| Database | MySQL | `scripts/backup.sh` (mysqldump) |
| Uploaded photos | the database (`STORAGE_DRIVER=db`) or `UPLOAD_DIR` | included in the dump / copy the directory |
| `.env` | repo root | password manager — **never** in git |

**Take a backup**

```bash
scripts/backup.sh                       # → backups/vision_eye_clinic-<UTC>.sql.gz
scripts/backup.sh /mnt/offsite/backups  # custom destination
BACKUP_RETENTION=30 scripts/backup.sh  # keep 30 dumps
```

**Restore**

```bash
# The target database must exist and be empty.
scripts/restore.sh backups/vision_eye_clinic-20261020T101500Z.sql.gz
```

**Schedule it** (Plesk cron, daily at 02:15):

```
cd /var/www/vision && /bin/bash scripts/backup.sh /var/www/vision/backups
```

**Copy the dumps off the host** — a backup on the same disk does not survive a
disk failure. Use rsync/SFTP to another machine or an object store.

**Rehearse a restore** before go-live: restore into a scratch database and
check the row counts.

---

## 13. Go-live checklist

Work top to bottom. Everything in **Critical** must pass.

### Critical

- [ ] `php artisan migrate --force` and `php artisan db:seed --force` ran on the
      production database.
- [ ] `php artisan tinker --execute="echo App\Models\Patient::count();"` → `0`
      (no demo data).
- [ ] The six real services are present and active
      (`php artisan tinker --execute="echo App\Models\Service::count();"` → `6`).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] A real `APP_KEY` is set (`php artisan key:generate`).
- [ ] `LOGIN_SECRET_PATH` is random and shared with the staff over a secure
      channel. `/login` and `/login/<wrong>` both return 404.
- [ ] The three seed accounts have **real** passwords, and each person changed
      it at first sign-in.
- [ ] `.env` is not in git and is readable only by the PHP user (`chmod 640`).
- [ ] HTTPS is forced; `SESSION_SECURE_COOKIE=true`.
- [ ] The document root is `public/`.
- [ ] The backup job is scheduled **and** a restore has been rehearsed.
- [ ] The cron job (`php artisan schedule:run`) is scheduled and `schedule:list`
      shows the entry.
- [ ] `scripts/smoke.sh` passes.
- [ ] `php artisan test` is green on the same commit (940 tests).

### Important

- [ ] `PUBLIC_BASE_URL` matches the real domain (canonical URLs, sitemap,
      `robots.txt`).
- [ ] The clinic name, currency, timezone and opening hours are set in
      **Settings**.
- [ ] The public site content (hero, address, phone, social links) is filled in
      under **Website content**.
- [ ] The announcement banner is switched off until there is something to
      announce.
- [ ] Feedback moderation is understood: new feedback is **hidden** until the
      owner publishes it.
- [ ] The staff photos and the service images are in place.
- [ ] The `Owner` account is the only one with the settings/audit rights you
      want; extra staff accounts are created as RECEPTION or STAFF.

### Nice to have

- [ ] A second person knows the `LOGIN_SECRET_PATH` and the backup location.
- [ ] The off-site backup copy is verified.
- [ ] `CRON_SECRET` is set (or the endpoint is deliberately left disabled).
- [ ] Uptime monitoring on `/up` (the Laravel health endpoint).

---

## 14. Updating the app later

```bash
# Locally
git pull
composer install --no-dev --optimize-autoloader

# On the host
cd /var/www/vision
# upload the changed files (app/, config/, database/, resources/, routes/, public/)
php artisan migrate --force        # only if there are new migrations
php artisan optimize:clear
```

`php artisan optimize:clear` flushes the config/route/view caches. If you
uploaded a new `.env` value, run `php artisan config:clear` (included in
`optimize:clear`).

**Never run `migrate:fresh` or `db:seed` blindly on production** —
`db:seed` is safe (idempotent), `migrate:fresh` destroys everything.

---

## 15. Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| 500 on every page | `APP_KEY` missing, or `storage/` not writable | `php artisan key:generate`; `chmod -R 775 storage bootstrap/cache` |
| "SQLSTATE[HY000] [1045] Access denied" | Wrong DB credentials | Re-check `.env`; `php artisan config:clear` |
| "Base table or view not found" | Migrations not run | `php artisan migrate --force` |
| CSS/JS missing (404) | `public/build/` not uploaded | Upload it, or build locally and commit |
| Login always 404 | Wrong `LOGIN_SECRET_PATH` | The URL is `/login/<the exact value>` — a wrong path is a hard 404 by design |
| "419 Page Expired" on forms | `SESSION_SECURE_COOKIE=true` while the site is still plain HTTP | Either finish the HTTPS setup or set it to `false` temporarily |
| Totals look wrong | `bcmath` missing | Enable `bcmath` in the Plesk PHP settings |
| Cron does not run | Wrong PHP binary path | Use the Plesk versioned binary (e.g. `/opt/plesk/php/8.3/bin/php`) |
| Uploads 404 | `STORAGE_DRIVER=db` but the `stored_files` table is empty | Run `php artisan migrate --force` |

More: `docs/troubleshooting.md`.
