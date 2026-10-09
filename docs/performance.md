# Performance notes

## Pagination & caps

The list screens are paginated; the operational boards are capped. These caps
are deliberate — they keep every page responsive and predictable. If a board
hits its cap, users should narrow with the on-screen filters.

| Screen | Behaviour |
| --- | --- |
| Patients | paginated, 20 / page (`PATIENTS_PAGE_SIZE`) |
| Sales | paginated, 20 / page (`SALES_PAGE_SIZE`) |
| Audit log | paginated, 25 / page (`AUDIT_PAGE_SIZE`) |
| Patient CSV/XLSX export | up to 5 000 rows (`EXPORT_ROW_CAP`) |
| Appointments (day/list) | up to 500 rows |
| Appointment requests | up to 200 rows |
| Follow-ups | up to 300 rows |
| Messages outbox | up to 500 rows |
| Campaign queue | up to 500 rows |
| Team leave / schedule reads | up to 100–1 000 rows per query |

Caps are per query in the module `queries.ts` files (search for `take:`).

## Database indexes

Migration `20261009031500_performance_indexes` adds indexes for the hot
predicates used by lists, boards, dashboards and exports:

| Table | Index | Used by |
| --- | --- | --- |
| `Staff` | `(active, isDoctor)` | doctor pickers, on-duty feed |
| `Patient` | `(active)` | patient list default filter |
| `Patient` | `(createdAt)` | newest-first ordering |
| `Appointment` | `(startsAt)` | day/week views, dashboards |
| `Sale` | `(paymentMethod)` | revenue breakdowns |
| `Sale` | `(status, voidRequestedBy)` | void-request inbox |
| `AuditLog` | `(entity, action, createdAt)` | audit viewer filters |

They mirror the `@@index` entries in `prisma/schema.prisma`. Apply with
`npm run db:deploy`.

## Load testing

Generate a realistic dataset (deterministic, idempotent-ish — safe to re-run to
grow data):

```bash
npm run db:load          # 5 000 patients, 20 000 sales (~90% CONFIRMED)
```

Then measure the pages that matter with the load data in place:

1. `/patients` first page and a search with a phone fragment.
2. `/sales` filtered by a date range.
3. `/revenue` for a 30-day period.
4. `/appointments` day view.
5. `/dashboard` (owner) cold load.
6. A patient export (CSV and XLSX).

Use the browser Network tab or:

```bash
curl -o /dev/null -s -w '%{time_total}\n' -b cookies.txt \
  'http://localhost:3000/api/patients/export?format=xlsx&...'
```

Expect sub-second responses on the list pages with these indexes applied. If a
query is slow, `EXPLAIN ANALYZE` against the load dataset and confirm the index
above is being used.

> Note: `db:load` is for **staging/dev only** — it writes a lot of synthetic
> patients and sales. Do not run it against production.

## Client-side

- Global search debounces keystrokes (200 ms) and the endpoint is session-gated.
- Message counts poll a cheap `count` endpoint and cache `no-store`.
- Public doctor/status reads are cached 60 s with `stale-while-revalidate=120`.
