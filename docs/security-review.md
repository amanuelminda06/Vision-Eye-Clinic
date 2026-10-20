# Security review

_Ported from the legacy security review (2026-10-09)._

Role constants live in each module's `roles.ts`; page/action/route guards call
`requirePageRole`, `requireRole` or `withAuth` with those constants. This table is a
static summary — the runtime guards are authoritative. Regenerate after adding endpoints.

## Endpoints & guards

| Endpoint / action | Guard | Roles |
| --- | --- | --- |
| `src/app/api/appointments/export/route.ts` | `requireRole` | OWNER, RECEPTION |
| `src/app/api/audit/export/route.ts` | `requireRole` | OWNER |
| `src/app/api/auth/[...nextauth]/route.ts` | public | — |
| `src/app/api/jobs/messages/route.ts` | `cron secret` | CRON |
| `src/app/api/leave/export/route.ts` | `requireRole` | OWNER, RECEPTION, STAFF |
| `src/app/api/messages/count/route.ts` | `auth()` | ANY (session) |
| `src/app/api/messages/export/route.ts` | `requireRole` | OWNER, RECEPTION |
| `src/app/api/patients/export/route.ts` | `requireRole` | OWNER, RECEPTION |
| `src/app/api/patients/template/route.ts` | `requireRole` | OWNER, RECEPTION |
| `src/app/api/public/doctors/[id]/photo/route.ts` | public | — |
| `src/app/api/public/doctors/route.ts` | public | — |
| `src/app/api/public/requests/route.ts` | public | — |
| `src/app/api/sales/export/route.ts` | `requireRole` | OWNER, RECEPTION |
| `src/app/api/search/route.ts` | `requireRole` | OWNER, RECEPTION |
| `src/app/api/uploads/[...key]/route.ts` | `auth()` | ANY (session) |
| `src/modules/appointments/actions.ts` | `withAuth` | OWNER, RECEPTION |
| `src/modules/auth/actions.ts` | `requireAuth` | ANY (session) |
| `src/modules/dashboard/actions.ts` | `withAuth` | OWNER |
| `src/modules/hr/actions.ts` | `withAuth` | OWNER |
| `src/modules/messaging/actions.ts` | `withAuth` | OWNER, RECEPTION |
| `src/modules/patients/actions.ts` | `withAuth` | OWNER, RECEPTION |
| `src/modules/public-site/actions.ts` | `withAuth` | OWNER |
| `src/modules/sales/actions.ts` | `withAuth` | OWNER, RECEPTION |
| `src/modules/services/actions.ts` | `withAuth` | OWNER |
| `src/modules/settings/actions.ts` | `withAuth` | OWNER |
| `src/modules/staff/actions.ts` | `withAuth` | OWNER |
| `src/modules/users/actions.ts` | `withAuth` | OWNER |

## Allow-listed (no session by design)

- `src/app/api/public/**` — public website (doctors list, booking request, photos).
- `/login/{secret}` — the secret-path sign-in/out.
- `src/app/api/uploads/**` — uploaded images, key-gated.
- `src/app/api/jobs/**` — scheduled jobs, `Authorization: Bearer $CRON_SECRET`.

## Needs manual review

_None — every scanned endpoint has a detected guard._

## Role capability summary

| Constant | Roles |
| --- | --- |
| `ALL_ROLES` | OWNER, RECEPTION, STAFF |
| `APPT_OWNER_ROLES` | OWNER |
| `APPT_ROLES` | OWNER, RECEPTION |
| `CONTENT_ROLES` | OWNER |
| `FOLLOW_UP_ROLES` | OWNER, RECEPTION |
| `HR_LEAVE_ROLES` | OWNER, RECEPTION, STAFF |
| `HR_MANAGE_ROLES` | OWNER |
| `HR_OWNER_ROLES` | OWNER |
| `HR_TEAM_LEAVE_ROLES` | OWNER, RECEPTION |
| `HR_VIEW_ROLES` | OWNER, RECEPTION, STAFF |
| `MESSAGE_OWNER_ROLES` | OWNER |
| `MESSAGE_ROLES` | OWNER, RECEPTION |
| `PATIENT_ROLES` | OWNER, RECEPTION |
| `REQUEST_ROLES` | OWNER, RECEPTION |
| `SALES_OWNER_ROLES` | OWNER |
| `SALES_ROLES` | OWNER, RECEPTION |
| `SERVICES_ROLES` | OWNER |
