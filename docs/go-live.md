# Go-live checklist

Work top to bottom. Do not launch with unchecked items in **Critical**.

## Critical (must pass)

- [ ] **Deployment** steps 1–3 done (`docs/deployment.md`); app reachable over HTTPS.
- [ ] `npm run typecheck && npm run lint && npm run messages && npm test` all green.
- [ ] `npm run build` succeeds; `bash scripts/smoke.sh` passes (173 checks).
- [ ] `npm run security:scan` shows **0** "needs manual review" endpoints.
- [ ] `npm run audit:deps` reports no high/critical advisories.
- [ ] Production `.env`: real `AUTH_SECRET`, random `LOGIN_SECRET_PATH`, real
      owner email, strong passwords; `.env` is **not** in git.
- [ ] All seeded demo passwords changed; `mustChangePassword` cleared for the
      real accounts after first login.
- [ ] Backup job scheduled **and** a restore has been rehearsed into a scratch
      database (`docs/backup-restore.md`).
- [ ] Off-site backup copy configured.
- [ ] `CRON_SECRET` set and the message job scheduled (or deliberately disabled).
- [ ] Every location/URL in README/this doc points at the real domain.

## Content & clinic readiness

- [ ] Clinic supplies final hero/about/hours/contact/footer/privacy copy
      (`docs/go-live.md` → "Public site content").
- [ ] Amharic copy reviewed by a native speaker (currently marked draft).
- [ ] Doctor profiles: correct staff flagged as doctors, `showOnWebsite`,
      specialty, photo (≤ 2 MB PNG/JPEG/WebP) — verify no phone/email leaks on
      `/doctors`.
- [ ] Service catalogue: public services + descriptions set; prices never shown.
- [ ] Consent-approved testimonials (or the section left empty).
- [ ] Settings: clinic name, timezone (`Africa/Addis_Ababa`), currency,
      opening hours, contact numbers, working-hour windows checked.
- [ ] Privacy policy reviewed (the shipped text is an explicit placeholder).

## People & process

- [ ] Owner, reception and staff accounts created; roles correct.
- [ ] Staff trained on the UAT script (`docs/uat.md`) and signed off.
- [ ] Manual test checklist in README items that need a human completed
      (especially real-device SMS link test, items 36 and 48 Lighthouse).
- [ ] Known gaps reviewed and accepted (`docs/known-gaps.md`).
- [ ] Support contact + escalation path agreed (see `docs/troubleshooting.md`).

## Day-1

- [ ] Take a fresh backup immediately after go-live.
- [ ] Watch the audit log (`/audit`) for unexpected `LOGIN_FAILED` bursts.
- [ ] Confirm `/api/public/doctors` loads on the live site with correct statuses.
- [ ] Confirm the messages badge / outbox matches what reception expects.

## Public site content (clinic to provide)

Before launch the clinic must supply the items listed in README →
"Public website — clinic-provided content": final copy, contact details,
reviewed Amharic, testimonials, doctor profiles/photos, public service
descriptions, analytics decision, `PUBLIC_BASE_URL`, optional Turnstile keys.
