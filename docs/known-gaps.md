# Known gaps

Deliberate limitations at this release. None block daily clinic use; each has a
seam for a follow-up slice.

## Product

- **No clinical records.** Patients hold contact/admin data only — never
  diagnoses, treatments or prescriptions. This is a design boundary.
- **SMS is manual-link only.** No gateway sends automatically; the app builds
  `sms:` links for the receptionist to send from the clinic phone.
  `GatewayProvider` (`src/modules/messaging/provider.ts`) is where a real SMS
  API would plug in.
- **Delivery is operator-confirmed, not verified.** The app cannot know an SMS
  was actually delivered; individual messages and group batches are marked sent
  by the operator (`docs/messaging-6b-manual.md` § 4).
- **Group chat is wizard/campaign-only.** One-group-chat delivery is never used
  by automatic task types (reminders, follow-ups, recalls).
- **Group messages share one body.** A group `sms:` link carries a single text;
  if a template personalises with `{name}`/`{date}`, the group shows the first
  recipient's values — use generic wording for groups.
- **Email** (daily summary) uses `EmailProvider`, console by default until an
  SMTP/API provider is configured.
- **Revenue** is preset-based (today/week/month/last-month/custom). No arbitrary
  grouped report builder.
- **Audit viewer** pretty-prints JSON; it is not diff-highlighted.
- **Rate limiting** is in-memory per process. Multi-instance deployments should
  move to Redis (the limiter is isolated in `src/lib/ratelimit.ts`).
- **Uploads** are stored on local disk behind `StorageProvider`; swap for S3/
  object storage for multi-instance or larger volumes.
- **Public site analytics** is an intentional no-op until a provider is chosen
  (enabling it also requires widening the CSP).
- **Operational board caps** (appointments 500, requests 200, messages 500,
  leave 100 rows) are fixed; see `docs/performance.md`.
- **No hard deletes** — records are deactivated, not removed.

## Content (clinic to supply)

- Final English copy and **native-reviewed Amharic** (shipped Amharic is a
  draft).
- Privacy policy review (current text is an explicit placeholder, not legal
  advice).
- Real contact details, testimonials (consent-approved), doctor photos and
  public service descriptions. See `docs/go-live.md`.

## Testing / operations

- `npm test` covers 200+ unit tests and `scripts/smoke.sh` covers 173 checks;
  the browser-level **README checklist (items 1–50) still needs a human**, in
  particular the real-device SMS test (item 36) and Lighthouse run (item 48).
- Playwright E2E specs exist under `e2e/` (`npm run test:e2e`) and require a
  seeded database and a running server; they are not wired into CI yet.
- Backup/restore requires PostgreSQL client tools, which the embedded dev
  database does not ship (`docs/backup-restore.md`).

## Explicitly out of scope

- Insurance/claims, inventory/pharmacy, lab orders, accounting exports,
  multi-branch/multi-tenant, patient self-service portal, mobile apps.
