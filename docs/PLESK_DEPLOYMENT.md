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

> **The lock file is resolved for PHP 8.3** (`composer.json` →
> `config.platform.php = 8.3.0`), because 8.3 is the oldest version this app
> supports. That is why the dependency set in `composer.lock` installs on
> PHP 8.3 **and** on 8.4 — do not run `composer update` on a newer PHP, or the
> lock will pull packages that need PHP 8.4 and the host will refuse to
> install them.

> **`short_open_tag` must be Off** (the Plesk/Debian default). It is off by
> default only when a php.ini is loaded — a bare PHP without one defaults to
> On, and then any literal XML prolog in a template is parsed as a short open
> tag. The sitemap is safe either way (the prolog is emitted from PHP as a
> string), and `scripts/php-version-check.php` guards the rest.

To check the host before deploying:

```bash
php -v                              # 8.3 or newer
php -r 'var_dump(extension_loaded("pdo_mysql"), extension_loaded("bcmath"));'
php -i | grep short_open_tag         # Off
composer check-platform-reqs         # every requirement satisfied
```

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

### Option P — the Plesk Git + Laravel deployment task (what this repo is set up for)

Plesk's **Laravel** application type clones the repository itself and runs
Composer for you. Two things must be right, or the task fails exactly like
it does with the wrong branch:

| Setting | Where | Value |
| --- | --- | --- |
| Repository URL | Domains → your-domain → **Git** | `https://github.com/amanuelminda06/Vision-Eye-Clinic.git` |
| **Branch** | Domains → your-domain → **Git** | **`laravel`** — the Laravel rebuild. The `main` branch still holds the legacy Next.js code and has **no `composer.json`**, so the task reports *"composer.json is invalid"*. |
| Document root | Domains → your-domain → **Document Root** | `<domain-root>/public` — never the project root |
| PHP version | Domains → your-domain → **PHP Settings** | **8.3 or newer** (the `composer.json` requires `^8.3`) |
| PHP extensions | Domains → your-domain → **PHP Settings** | `pdo_mysql`, `mbstring`, `bcmath`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo` |

Then **Deploy** again. The task clones the branch, runs `composer install`
(`composer.lock` is committed, so the host runs exactly the tested dependency
set) and configures the Laravel app.

The Composer step runs `artisan package:discover`, which boots the app
**before the `.env` exists** — so the app must tolerate a missing
`LOGIN_SECRET_PATH` there. It does: an empty value is only refused when the
app is SERVING in production (see `App\Support\LoginSecret`). A malformed
value is refused everywhere.

After the task succeeds, finish by hand — it does not do these:

```bash
cd /httpdocs                       # or whatever your document root is
cp .env.example .env
php artisan key:generate
chmod 640 .env
# edit .env (section 5), then:
php artisan migrate --force
php artisan db:seed --force
chown -R <plesk-ftp-user>:<plesk-ftp-user> storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
php artisan optimize:clear
```

**Write the secrets with `scripts/env-set.php`, never with `sed`.** A MySQL
password from Plesk can contain a space, a `#` or a `$`. Pasted raw into the
`.env` an unquoted space makes **Dotenv throw while it is still loading the
file** — the app then dies *before* Laravel starts and the web server shows a
bare `500 Internal Server Error` with nothing in the Laravel log. The helper
reads the value from a hidden prompt and quotes it correctly:

```bash
cd /httpdocs
php scripts/env-set.php DB_PASSWORD      # hidden prompt, quoted safely
php scripts/env-set.php LOGIN_SECRET_PATH
php scripts/env-set.php OWNER_PASSWORD
```

It never prints the value and never puts it in the shell history. The
equivalent for the other keys is to quote them by hand in the `.env`:
`DB_PASSWORD='my secret'`.

To see the state of the `.env` without printing a single value (which key is
missing, which is empty, and whether Dotenv can even parse the file):

```bash
cd /httpdocs && php scripts/diagnose-env.php
```

Then run the pre-flight check — it reports EVERYTHING that is still missing
or wrong, in one go, and never prints a secret:

```bash
cd /httpdocs && bash scripts/preflight.sh
```

It checks the PHP version and extensions, the `.env` keys (only whether they
are set), the database connection, the migration state, the seed, the
writable paths, the document root, the compiled assets and the scheduler.
A clean run ends with `N passed, 0 failed` — that is the go-live signal.

> If the task still clones the wrong branch, set **laravel** as the default
> branch in GitHub (Settings → Branches → Default branch) — or ask the
> hosting administrator to change the branch in the Git settings above.

---

## 4. Install the dependencies

Two options — pick one (skipped when you use the Plesk Git task above).

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
| `APP_URL` | `https://visioneye.clinic.et` | No trailing slash. **Must be the real public origin** — see the note below. |
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
| `PUBLIC_BASE_URL` | `https://visioneye.clinic.et` | Same value as `APP_URL` — the site has ONE public origin. Used for the canonicals, the sitemap and `robots.txt`. |
| `SESSION_SECURE_COOKIE` | `true` | Once the site is HTTPS-only. |
| `CRON_SECRET` | `openssl rand -hex 24` | Bearer token for the message job. Leave blank to disable the endpoint. |
| `STORAGE_DRIVER` | `db` | Uploads as database blobs (survives a disk wipe). Use `local` + `UPLOAD_DIR` for the filesystem instead. |

### `APP_URL` must be the REAL origin

`APP_URL` (and `PUBLIC_BASE_URL`, which is the same value) is not cosmetic.
A wrong value breaks the site in ways that look like "the design is broken":

| What breaks | How |
| --- | --- |
| **Every asset URL** | `asset()`, `url()` and `route()` build absolute links from `APP_URL`. With `APP_URL=http://…` the pages on the https site link to `http://…` — the browser blocks them as **mixed content**, so the stylesheet, the logo, the hero image and the fonts silently disappear and the page renders as unstyled text. |
| **The login redirect** | The staff login (`/login/<LOGIN_SECRET_PATH>`) and the "back to the site" redirect are built from `APP_URL`. A wrong value sends the operator to a host that does not exist (`clinic.example.com`). |
| **The canonical URL / og:url / sitemap** | Search engines treat `http://clinic.example.com/` and `https://visioneye.clinic.et/` as two different sites. |

Set it once, with the scheme, without a trailing slash:

```dotenv
APP_URL=https://visioneye.clinic.et
PUBLIC_BASE_URL=https://visioneye.clinic.et
```

Behind the Plesk nginx proxy the app trusts `X-Forwarded-Proto` (`TRUSTED_PROXIES`,
default the loopback) and forces `https` in production — but `APP_URL` is still
the source of truth for the absolute URLs the templates print.

Everything else keeps its default. The full list with comments is in
`.env.example`.

> **Quote any value that contains a space, a `#` or a `$`** — e.g.
> `DB_PASSWORD='my secret'`. An unquoted space makes Dotenv fail while it is
> still reading the file, and the site then returns a bare `500` with nothing
> in the Laravel log. `php scripts/env-set.php <KEY>` does it for you (hidden
> prompt, correct quoting).

> **Never commit `.env`.** It is already in `.gitignore`.

---

## 6. Create the schema and seed

```bash
cd /var/www/www/vision   # your path
php artisan migrate --force
php artisan db:seed --force
```

The production seed creates **only**:

- the 19 clinic settings,
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

The CSS/JS are **committed** under `public/build/` — the directory travels
with the repository. The host needs **no Node.js** and no build step.

The build toolchain is back in the repository (`package.json`,
`vite.config.js`, Tailwind v4 + Vite + Leaflet). Rebuild the assets locally
and commit the result whenever a view, the CSS or the JS changes:

```bash
npm install
npm run build
git add -A public/build
git add public/build package-lock.json
git commit -m "build: refresh the compiled assets"
```

`npm run build` writes a NEW file name for every entry (the name is hashed),
so the old files must be removed from the repository in the same commit —
`git add -A public/build` does that.

After any front-end change (and after the first deploy):

```bash
php artisan optimize:clear
```

> The compiled CSS must be rebuilt whenever a **new utility class** appears in
> a view: Tailwind only emits the classes it finds in the source. A stale
> bundle is what makes a page look "partly broken".

### The fonts are self-hosted

The web fonts live in `resources/fonts/` as `woff2` files:

| File | Family | Covers |
| --- | --- | --- |
| `instrument-sans-latin.woff2` | Instrument Sans | the Latin text (variable, 400–700) |
| `instrument-sans-latin-ext.woff2` | Instrument Sans | the Latin extended characters |
| `noto-sans-ethiopic.woff2` | Noto Sans Ethiopic | the Amharic text (አማርኛ) |

They are `@font-face` declarations in `resources/css/app.css`, so Vite hashes
them into `public/build/assets/` with the CSS — the browser loads them from
**this host**. No `fonts.googleapis.com`, no third party font server, and the
CSP stays at `font-src 'self' data:`.

> **A missing font file is not a fatal error** — the browser falls back to the
> system sans-serif — but a *stale* CSS bundle that predates the `@font-face`
> rules shows the system font instead of the brand font on every page. Rebuild
> and commit `public/build` whenever the fonts change.

### The map

The contact page shows an OpenStreetMap map with **one** tile host,
`https://tile.openstreetmap.org/{z}/{x}/{y}.png` (no `a/b/c` subdomains), and
the CSP allows exactly that host in `img-src`. Leaflet's CSS and its marker
icons are part of the committed bundle. The scroll wheel does **not** zoom
(the page keeps scrolling), and on a touch screen a one-finger swipe scrolls
the page while the map is panned with two fingers. The address and an
"Open in Google Maps" link are always visible next to the map — they are the
fallback when the tiles cannot be loaded.

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

## 11. After EVERY Git deployment

> **A Plesk Git deployment never DELETES a file that was removed from the
> repository.** It only adds and overwrites. Every file the old Next.js app
> left behind therefore **stays on the server**:
>
> - the folders `public/services` and `public/doctors` — a folder in `public/`
>   **SHADOWS a Laravel route**: Apache answers `/services` from
>   `public/services` before the framework ever runs, and the page breaks
>   with no PHP error at all;
> - the stock svg files `next.svg`, `vercel.svg`, `file.svg`, `globe.svg`,
>   `window.svg`, `index.html`;
> - the legacy images at the root of `public/` — `hero.jpg`, `hero-medical.svg`,
>   `vision-logo.png`, `vision-logo-dark.png`, `vision-logo.jpg` (they now
>   live under `public/images/`).
>
> The same is true for the **hashed build files**: `public/build/assets/` keeps
> every old `app-<hash>.css` / `app-<hash>.js`. They are harmless (nothing
> links to them any more) but they pile up — delete them by hand or ignore
> them.

So after every deployment, run this (it is safe to run every time):

```bash
cd /var/www/vision          # or /httpdocs on Plesk

php artisan public:clean-legacy   # remove the known legacy leftovers
php artisan public:check          # fail if public/ is still broken
php artisan optimize:clear
php artisan migrate --force
php artisan storage:link
```

`public:clean-legacy` deletes **only** the hard-coded list of known legacy
entries and never touches anything else. Add `--dry-run` to see what it
would remove first. `public:check` exits non-zero when

- an entry in `public/` shadows a registered route,
- a referenced asset is missing,
- the built assets the Vite manifest promises are missing,
- `favicon.ico` is missing or empty.

Then **check these pages in the browser** (they are the ones a stale file can
break):

| URL | What to look for |
| --- | --- |
| `/` | the hero image, the CSS (brand colours), the logo |
| `/services` | the six services — NOT a 404 from `public/services` |
| `/doctors` | the doctors — NOT a 404 from `public/doctors` |
| `/contact` | the map, the address, the "Open in Google Maps" link |
| `/book` | the booking form |
| `/login/<LOGIN_SECRET_PATH>` | the login form |

Open the pages in a **private window** (a cached `app-<hash>.css` hides a
broken build) and check the browser console: a missing file shows a 404 and
the CSP shows the host it was blocked on.

### Running the commands automatically

Plesk → **Websites & Domains → your-domain → Git → "Additional deployment
actions"** (the hook that runs after the pull). Set it to something like:

```bash
cd /var/www/vision && php artisan public:clean-legacy && php artisan public:check && php artisan optimize:clear && php artisan migrate --force && php artisan storage:link
```

(`public:check` exits non-zero when something is wrong, so the deployment is
marked as failed instead of silently serving a broken page.)

> The hook runs as the deployment user. If it fails with a permission error on
> `storage/`, run `chmod -R 775 storage bootstrap/cache` once (section 7).

---

## 12. Post-deploy smoke test

```bash
# Locally or on the host (the host must reach the site):
BASE=https://visioneye.clinic.et bash scripts/smoke.sh
```

The script logs in through the secret path and checks the ERP screens, the
public pages, the exports and the API contracts. Expect a clean run
(69 checks).

It also checks that **every public page renders in Amharic** (`?lang=am`) —
the expected strings are read from `lang/am/*.php`, so a translation change
never breaks the check. Run it from the repository root (the script reads the
lang files and needs the `php` CLI).

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

## 13. Backups

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

## 14. Go-live checklist

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
- [ ] `scripts/preflight.sh` passes (0 failed) — it checks the `.env`, the
      database, the migrations, the seed, the permissions and the scheduler.
- [ ] `php artisan public:clean-legacy` and `php artisan public:check` pass
      (no legacy leftovers, no route shadowing, no missing asset).
- [ ] The home, services, doctors, contact and login pages were opened in the
      browser after the deployment.
- [ ] `scripts/smoke.sh` passes.
- [ ] `php artisan test` is green on the same commit (1051 tests).

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

## 15. Updating the app later

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

## 16. Troubleshooting

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
| `/services` or `/doctors` 404 or 500, but the same URL works locally | A leftover folder in `public/` **shadows the route** — Apache serves it before Laravel runs | `php artisan public:clean-legacy` (a Plesk deployment never deletes the files the old app left) |
| The page loads but looks unstyled / the hero image is missing | The compiled assets in `public/build/` are stale (Tailwind only emits the classes it finds) or missing | `npm install && npm run build`, commit `public/build/`, redeploy; `php artisan optimize:clear` |
| The map is blank | The tile images are blocked, or the map is not initialised | Check the CSP allows `https://tile.openstreetmap.org`; check `public/build/assets/app-*.js` is not empty |
| A bare `500` with NOTHING in the Laravel log | The `.env` cannot be parsed (an unquoted value with a space) — PHP dies before the framework starts | `php scripts/env-set.php <KEY>`, or quote the value: `DB_PASSWORD='my secret'` |
| The site is on HTTPS but the CSS/JS URLs are `http://` | The proxy headers are not trusted | Set `TRUSTED_PROXIES` in the `.env`; the scheme is forced in production |
| `favicon.ico` is empty / the tab shows a broken icon | The file was never committed | `php artisan public:check` reports it; the icon is in `public/favicon.ico` |

More: `docs/troubleshooting.md`.
