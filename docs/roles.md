# Role guides

Three roles exist. Authorization is enforced server-side
(`requireRole`/`requirePageRole`) and mirrored in the UI (menu + `<RoleGate>`).

## OWNER (clinic owner / manager)

Sees everything. Day to day:

- **Dashboard** — role overview + **Daily summary** (`/dashboard/daily`) with
  today's revenue, average sale, voided total, receipt range and breakdowns by
  payment/doctor/service; print it or email it.
- **Patients, Sales, Appointments, Requests, Follow-ups, Messages** — full access.
- **Messages 6B** — manages the Message Library (archive/reuse/favorites),
  saved audiences, and the Group-send override; see
  `docs/messaging-6b-manual.md`.
- **Revenue** — period presets, totals, breakdowns and daily trend. Voided sales
  are shown separately and excluded from revenue.
- **Void approvals** (`/sales/voids`) — approve/reject reception's void requests.
- **Schedule & Leave** — manage shifts/templates; approve/reject leave
  (`/leave/inbox`); cannot decide their own leave (a peer does).
- **Users & Staff** — create/edit/deactivate, reset passwords, set roles and
  doctor/website flags, upload photos.
- **Services** — catalogue + price history; publish services to the website.
- **Settings** — clinic name, timezone, currency, hours, contacts, campaign cap,
  and the group-SMS controls (`group_sms_allow_for_filtered`,
  `group_sms_max_recipients`).
- **Website content** (`/dashboard/content`) — CMS-lite text for the public site.
- **Audit log** — filter and export (CSV/Excel) every recorded change.
- **Exports & search** — CSV/Excel on Patients, Sales, Audit, Appointments,
  Messages and Leave; header search for patients/sales/appointments.

## RECEPTION (front desk)

Front-office operations; **no revenue, no admin**.

- **Dashboard** — today's appointments, new requests, messages due, follow-ups.
- **Patients** — create/edit/search, tags, SMS consent and stop-messages.
- **Sales** — new sale (patient or walk-in), receipts (A5/thermal), request a
  void; sees **only their own sales for the current clinic day**.
- **Appointments** — create/edit/transition (confirm, complete, no-show, cancel).
- **Requests** — process website bookings, convert to appointments.
- **Follow-ups** — recall list; mark done / cancel.
- **Messages** — work the outbox: send via the phone's SMS app and confirm;
  compose via the 3-step wizard (`/messages/new`), save drafts, and reuse saved
  library messages; templates/campaigns only if enabled
  (`reception_can_send_campaigns`). The Message Library and saved audiences are
  managed by the owner, and group-sending for filtered audiences requires the
  owner's override.
- **Schedule & Leave calendar** — team view only (no edit controls).
- **Leave** — request their own leave.

## STAFF (doctor / clinician)

Minimal footprint.

- **Dashboard** — *my* appointments today, *my* shifts this week, leave balance.
- **Schedule** — own week only.
- **Leave** — request/cancel own leave; see balance.
- No access to patients, sales, revenue, appointments admin, requests,
  follow-ups, messages, users, staff, settings, services, content or audit.

## Common to all

- Sign in only via `/login/<LOGIN_SECRET_PATH>`; first login forces a password
  change.
- Switch EN/AM from the header.
- Change your own password from the user menu; sign out there too.
- If you reach a page you may not use, you get the friendly `/403` page — tell
  the owner if you think your role is wrong.
