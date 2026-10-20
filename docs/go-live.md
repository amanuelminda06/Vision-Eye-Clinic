# Go-live checklist

Work top to bottom. Do not launch with unchecked items in **Critical**.

## Critical (must pass)

- [ ] **Deployment** steps done ([PLESK_DEPLOYMENT.md](PLESK_DEPLOYMENT.md));
      the app is reachable over HTTPS and the document root is `public/`.
- [ ] `php artisan test` is green on the deployed commit.
- [ ] `./vendor/bin/pint --test` is clean.
- [ ] `php artisan migrate --force` and `php artisan db:seed --force` ran on
      the production database.
- [ ] `php artisan tinker --execute="echo App\Models\Patient::count();"` → `0`
      (the production seed creates no demo data).
- [ ] `php artisan tinker --execute="echo App\Models\Service::count();"` → `6`
      (the six real services).
- [ ] `bash scripts/smoke.sh` passes.
- [ ] Production `.env`: a real `APP_KEY`, a random `LOGIN_SECRET_PATH`, real
      owner/reception/staff emails and strong passwords; `APP_DEBUG=false`;
      `.env` is **not** in git.
- [ ] `/login` and `/login/<wrong secret>` both return **404**.
- [ ] Every seeded password was changed; the real accounts have
      `mustChangePassword` cleared.
- [ ] The backup job is scheduled **and** a restore has been rehearsed into a
      scratch database ([backup-restore.md](backup-restore.md)).
- [ ] An off-site copy of the backups is configured.
- [ ] The cron job (`php artisan schedule:run`) is scheduled and
      `php artisan schedule:list` shows the entry.
- [ ] `bcmath` is enabled in the host's PHP settings.

## Important

- [ ] `PUBLIC_BASE_URL` matches the real domain (canonical URLs, sitemap,
      `robots.txt`).
- [ ] The clinic name, currency, timezone and opening hours are set in
      **Settings**.
- [ ] The public site content (hero, address, phone, social links) is filled
      in under **Website content**.
- [ ] The announcement banner is switched off until there is something to
      announce.
- [ ] Feedback moderation is understood: new feedback is **hidden** until the
      owner publishes it.
- [ ] The staff photos and the service images are in place.
- [ ] Only the accounts that need the owner rights have the `OWNER` role.
- [ ] `SESSION_SECURE_COOKIE=true` (the site is HTTPS-only).

## Nice to have

- [ ] A second person knows the `LOGIN_SECRET_PATH` and where the backups
      live.
- [ ] Uptime monitoring on `/up` (the Laravel health endpoint).
- [ ] `CRON_SECRET` set (or the message-job endpoint deliberately left
      disabled).
- [ ] The public booking form was tested end to end on the real domain.

## After go-live

- [ ] Watch the audit log (`/audit`) for the first days.
- [ ] Check that the daily summary (`/dashboard/daily`) matches the paper
      records for the first week.
- [ ] Confirm the public "on duty" feed (`/api/public/doctors`) shows the
      right doctors.
