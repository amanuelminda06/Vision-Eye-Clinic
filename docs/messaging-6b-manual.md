# Messaging — Slice 6B manual

This covers the richer messaging workflow shipped in Slice 6B:

1. richer recipient filters + a server-side audience preview
2. delivery choice **Individually** vs **One group chat**
3. in-app composer with drafts (the app itself never sends)
4. Message Library (archive & reuse with per-language duplicate prevention)
5. saved audiences, permissions, audit, and full English/Amharic i18n

Everything still exits through the same seam: the clinic phone. The app builds
`sms:` links (one per recipient, or one per group) and the operator sends and
then confirms. There is **no automatic sending**.

---

## 1. Roles & permissions

| Ability | OWNER | RECEPTION | STAFF |
| --- | --- | --- | --- |
| View drafts / library / compose messages | ✔ | ✔ | ✘ |
| Send (prepare) campaigns | ✔ | ✔* | ✘ |
| Manage the Message Library | ✔ | ✘ | ✘ |
| Save / delete saved audiences | ✔ | ✘ | ✘ |
| Group chat for filtered audiences (override) | ✔ (setting) | ✘ | ✘ |

\* RECEPTION can send campaigns only when the `reception_can_send_campaigns`
setting is on. STAFF has no messages access (`MESSAGE_ROLES` = OWNER/RECEPTION).

The group override is **OWNER-only**. Staff simply see the restriction message
and choose a different audience or individual delivery.

---

## 2. Where things live

- **Messages** (`/messages`) — outbox today, plus quick links to **New
  message**, **Library**, and **Drafts**.
- **New message** (`/messages/new`) — the full 3-step wizard, pre-set to
  *Individually*.
- **New campaign** (`/messages/campaigns/new`) — the same wizard.
- **Library** (`/messages/library`) — saved messages, reuse, favorites.
- **Drafts** (`/messages/drafts`) — your in-progress messages.

---

## 3. The 3-step wizard

Shared by both "New message" and "New campaign".

### Step 1 — Audience

Filters (all combine with AND unless noted):

- **Quick preset** — none / last 30 / last 90 / last 180 days / this year
  (resolved server-side against the clinic time zone).
- **Language** — preferred language (English/Amharic).
- **Tags** — comma-separated; choose *any* or *all* of these tags.
- **Visited between** — date range over the last appointment **or** sale.
- **Bought a service between** — date range over the last **confirmed** sale.
- **Upcoming** — any / has / no upcoming appointment.
- **Service category** and **specific services** — matches on purchased services.
- **Birthday month**.
- **Hand-picked patients** — type-ahead search; chosen patients are **added to**
  whichever criteria you also set (union). Hard gates still apply to everyone,
  hand-picked or not.
- **Saved audiences** — apply a previously saved filter (saved by OWNER).

Click **Preview** to see how many are matched vs actually eligible (consent,
active, valid phone, do-not-message, cancelled source all reduce the number).
If the eligible count exceeds the `campaign_max_recipients` cap (default 300)
you must narrow the filter or raise the cap in Settings.

### Step 2 — Message

- Pick a **template**, a **custom message**, or **reuse a library message** /
  **load a draft**.
- Custom messages are validated: non-empty, under 1000 chars, only known
  variables (`{name} {date} {time} {doctor} {clinic} {review_link}
  {reopen_date}`). Sensitive words (diagnoses, surgery, pricing, `ብር`) are
  warned, not blocked.
- **Save draft** — private to you, for later. From step 2 you can also
  **Archive to library** (OWNER).
- Language mode: per-patient or one fixed language.

### Step 3 — How to send

Choose **Individually** or **One group chat**.

- **Individually** — the queue opens one `sms:` link per recipient.
- **One group chat** — recipients are split into batches of
  `group_sms_max_recipients` (default 15). The operator opens one `sms:` link
  per batch (the handset addresses all numbers in that batch).

The <em>acknowledge</em> checkbox appears for group chat: everyone in the group
sees every phone number. A group mode is **only enabled after an audience
preview exists** — you cannot blind-send to a group you did not preview.

---

## 4. Group-chat safety rules

By default a *filtered* audience **cannot** be used for group chat. Allowed
without any owner override:

- audiences with **no criteria at all** (unrestricted),
- a **plain visited-between** range (quick preset without other criteria or
  hand-picked patients).

Restricted by default (list of reasons shown in the UI):

- specific services, tags, service category,
- "has / has no upcoming appointment",
- "bought a service between",
- any combination of the above (falls back to a generic "filtered" block).

The OWNER can turn them on with the
`group_sms_allow_for_filtered` **Override group sending for filtered
audiences** setting. With the override on, filtered audiences become available
but still require the acknowledge checkbox and a fresh preview.

### Batch size

- `group_sms_max_recipients` (Settings, OWNER) is clamped to 1–100; default 15.
- Splitting fills earlier batches first. Example: **40 recipients at 15 →
  15 / 15 / 10.**
- Campaigns created before 6B or by automatic roles never go through group
  mode (group is a manual, campaign-send feature only).

### Marking batches

The app **cannot detect** that you actually sent the group message. After
opening a batch link the queue stays open and you either:

- **Yes, sent** → batch and its recipients become `SENT`, on your audit trail,
  or
- **Skip** (reason required) → `SKIPPED`.

**Cancel remaining** cancels pending recipients *and* pending batches whenever
the operator chooses to stop.

---

## 5. Message Library

- Saved messages appear under **Library** with a category
  (reminder/greeting/announcement/follow-up/other) and language.
- **Reuse** on a library item opens the composer with the message preloaded.
- **Archive** a library message and it becomes available to reuse via
  "Archive to library" in the wizard.
- **Per-language duplicate guard**: the same wording cannot be added twice in
  the same language — a second copy is rejected with a clear message.
  English and Amharic versions of the same text are treated as different items.
- Favorites float to the top; the most-used message wins within same
  favorites.
- Deleting is a soft delete so it never breaks historical campaigns.

## 6. Drafts

- Entered in the wizard, save button (or saved for later on Purpose step).
- Private to the user who created them.
- Load from the Drafts page or the reuse list; loading a draft switches you to
  *Custom* message mode.

## 7. New settings

| Setting key | Default | Purpose |
| --- | --- | --- |
| `campaign_max_recipients` | 300 | Hard cap on eligible recipients |
| `group_sms_allow_for_filtered` | false | OWNER override for group chat on filtered audiences |
| `group_sms_max_recipients` | 15 | Per-batch size for group chat (1–100) |

## 8. Audit trail

All of the following actions are audited (entity + actor + before/after):
`CAMPAIGN_ACTIVATED` (with delivery mode and batch count),
`CAMPAIGN_BATCH_HANDLED`, `CAMPAIGN_CANCELLED`, `DRAFT_UPSERTED`,
`LIBRARY_ITEM_CREATED/HIDDEN/FAVORITED`, `AUDIENCE_CREATED/UPDATED/DELETED`.

## 9. Seeds

- `npm run db:seed` also loads **10 library messages** (English + Amharic) and
  **2 saved audiences** (English consenters; last-90-days visitors).
- `tsx prisma/seed-group-e2e.ts allow|block` creates the deterministic 40-patient
  group E2E fixture (unique tag) and toggles the override.

## 10. Known limits — read these

- The app never verifies actual SMS delivery; group and individual statuses are
  operator-confirmed.
- `One group chat` is not available for automatic task types (reminders,
  follow-ups, recalls) — campaign/wizard creation only.
- In a group, the shared body is the one rendered for the first recipient; if a
  template contains `{name}` etc., the *group* message will contain that first
  person's values. Prefer generic wording (e.g. `{clinic}` or none) for groups.
- `sms:` links rely on the handset's default SMS app; group links use the
  comma-separated recipient syntax supported by Android/iOS Messages.