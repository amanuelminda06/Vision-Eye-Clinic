#!/bin/bash
# Production smoke test against http://localhost:3100
# Note: with cacheComponents the status line is committed before the page's
# dynamic pass runs, so post-login redirects arrive as NEXT_REDIRECT digests
# in the RSC flight payload (client-side), not as HTTP 307s. Unauthenticated
# redirects (from the layout gate) are still HTTP 307s.
set -u
cd "$(dirname "$0")/.."
BASE=http://localhost:3100
SECRET=$(grep "^LOGIN_SECRET_PATH=" .env | cut -d= -f2- | tr -d "\"")
PASS=0; FAIL=0

check() { # check <desc> <expected> <actual>
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "✓ $1";
  else FAIL=$((FAIL+1)); echo "✗ $1 (expected: $2, got: $3)"; fi
}
contains() { # contains <desc> <needle> <file>
  if grep -qi "$2" "$3"; then PASS=$((PASS+1)); echo "✓ $1";
  else FAIL=$((FAIL+1)); echo "✗ $1 (missing: $2)"; fi
}
login() { # login <jar> <email> <password>
  local jar=$1 email=$2 password=$3
  local token
  token=$(curl -s -c "$jar" "$BASE/api/auth/csrf" | sed -E 's/.*"csrfToken":"([^"]+)".*/\1/')
  curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$BASE/api/auth/callback/credentials" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-urlencode "csrfToken=$token" \
    --data-urlencode "email=$email" \
    --data-urlencode "password=$password" \
    --data-urlencode "json=true"
}
has_session() { grep -q "session-token" "$1"; }
sql() { node --input-type=module -e "
import { PrismaClient } from '@prisma/client';
const p = new PrismaClient();
$1
await p.\$disconnect();"; }

rm -f /tmp/opencode/erp-smoke*.jar /tmp/opencode/*.html /tmp/opencode/*.headers /tmp/opencode/audit.csv

# 1. Secret link gate
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login/totally-wrong")
check "wrong secret → 404" 404 "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login")
check "/login → 404" 404 "$code"
code=$(curl -s -o /tmp/opencode/login.html -w '%{http_code}' "$BASE/login/$SECRET")
check "correct secret → 200" 200 "$code"
contains "login page renders" "password" /tmp/opencode/login.html

# 2. Security headers
curl -s -D /tmp/opencode/login.headers -o /dev/null "$BASE/login/$SECRET"
contains "CSP header" "content-security-policy" /tmp/opencode/login.headers
contains "X-Frame-Options DENY" "x-frame-options: DENY" /tmp/opencode/login.headers
contains "nosniff" "x-content-type-options: nosniff" /tmp/opencode/login.headers
contains "Referrer-Policy" "referrer-policy" /tmp/opencode/login.headers

# 3. Unauthenticated app routes redirect to the secret login (HTTP 307 from layout)
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
check "no session / → 307" 307 "$code"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/")
case "$loc" in *"/login/$SECRET"*) PASS=$((PASS+1)); echo "✓ redirect target is secret login";;
  *) FAIL=$((FAIL+1)); echo "✗ redirect target: $loc";; esac
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/users")
check "no session /users → 307" 307 "$code"

# 4. Owner login + pages
JAR=/tmp/opencode/erp-smoke.jar
login "$JAR" owner@visioneye.et "$(grep '^OWNER_PASSWORD=' .env | cut -d= -f2- | tr -d '\"')"
if has_session "$JAR"; then PASS=$((PASS+1)); echo "✓ owner login sets session"; else FAIL=$((FAIL+1)); echo "✗ owner login: no session cookie"; fi
code=$(curl -s -b "$JAR" -o /tmp/opencode/users.html -w '%{http_code}' "$BASE/users")
check "owner /users → 200" 200 "$code"
contains "users page title" "Users" /tmp/opencode/users.html
contains "users table has rows" "/users/cm" /tmp/opencode/users.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/audit.html -w '%{http_code}' "$BASE/audit")
check "owner /audit → 200" 200 "$code"
contains "audit page renders" "Audit" /tmp/opencode/audit.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/settings.html -w '%{http_code}' "$BASE/settings")
check "owner /settings → 200" 200 "$code"
contains "settings timezone field" "Africa/Addis_Ababa" /tmp/opencode/settings.html

# 5. Owner CSV export
code=$(curl -s -b "$JAR" -o /tmp/opencode/audit.csv -w '%{http_code}' "$BASE/api/audit/export")
check "CSV export → 200" 200 "$code"
contains "CSV has BOM + header" "time,user,email,entity" /tmp/opencode/audit.csv
contains "CSV contains LOGIN_SUCCESS" "LOGIN_SUCCESS" /tmp/opencode/audit.csv

# 6. Uploads require a session
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/uploads/staff-nope.png")
check "upload without session → 404" 404 "$code"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/api/uploads/staff-nope.png")
check "upload missing file → 404" 404 "$code"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/api/uploads/..%2F..%2Fetc%2Fpasswd")
check "traversal key → 404" 404 "$code"

# 7. Reception: forced-change gate first, then 403 for owner-only pages
JAR2=/tmp/opencode/erp-smoke2.jar
login "$JAR2" reception@visioneye.et "$(grep '^RECEPTION_PASSWORD=' .env | cut -d= -f2- | tr -d '\"')"
if has_session "$JAR2"; then PASS=$((PASS+1)); echo "✓ reception login sets session"; else FAIL=$((FAIL+1)); echo "✗ reception login: no session cookie"; fi
code=$(curl -s -b "$JAR2" -o /tmp/opencode/rec-users.html -w '%{http_code}' "$BASE/users")
check "reception /users → 200 (flight redirect)" 200 "$code"
contains "reception forced-change redirect" "NEXT_REDIRECT;replace;/change-password" /tmp/opencode/rec-users.html
if grep -q "owner@visioneye" /tmp/opencode/rec-users.html; then FAIL=$((FAIL+1)); echo "✗ reception /users leaks owner rows"; else PASS=$((PASS+1)); echo "✓ reception /users leaks no data"; fi
code=$(curl -s -b "$JAR2" -o /tmp/opencode/403.html -w '%{http_code}' "$BASE/403")
check "/403 renders → 200" 200 "$code"
contains "403 content" "403" /tmp/opencode/403.html
# clear reception's forced change, log in fresh (JWT claims are set at login),
# expect the role redirect to /403 instead
sql "await p.user.update({ where: { email: 'reception@visioneye.et' }, data: { mustChangePassword: false } }); console.log('reset');"
JAR2B=/tmp/opencode/erp-smoke2b.jar
login "$JAR2B" reception@visioneye.et "$(grep '^RECEPTION_PASSWORD=' .env | cut -d= -f2- | tr -d '\"')"
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-users2.html -w '%{http_code}' "$BASE/users")
check "reception /users (no force) → 200 (flight redirect)" 200 "$code"
contains "reception forbidden redirect" "NEXT_REDIRECT;replace;/403" /tmp/opencode/rec-users2.html
sql "await p.user.update({ where: { email: 'reception@visioneye.et' }, data: { mustChangePassword: true } }); console.log('restored');"

# 8. Staff: forced password change (clean any leftover lockout first)
sql "await p.user.update({ where: { email: 'doctor@visioneye.et' }, data: { failedLoginCount: 0, lockedUntil: null } }); console.log('unlocked');"
JAR3=/tmp/opencode/erp-smoke3.jar
login "$JAR3" doctor@visioneye.et "$(grep '^STAFF_PASSWORD=' .env | cut -d= -f2- | tr -d '\"')"
if has_session "$JAR3"; then PASS=$((PASS+1)); echo "✓ staff login sets session"; else FAIL=$((FAIL+1)); echo "✗ staff login: no session cookie"; fi
code=$(curl -s -b "$JAR3" -o /tmp/opencode/staff-home.html -w '%{http_code}' "$BASE/")
check "staff / → 200 (flight redirect)" 200 "$code"
contains "staff forced-change redirect" "NEXT_REDIRECT;replace;/change-password" /tmp/opencode/staff-home.html
code=$(curl -s -b "$JAR3" -o /tmp/opencode/chpw.html -w '%{http_code}' "$BASE/change-password")
check "/change-password renders → 200" 200 "$code"
contains "change password form" "newPassword" /tmp/opencode/chpw.html

# 9. Lockout after 5 failed attempts (fresh jar → no session until success)
JAR4=/tmp/opencode/erp-smoke4.jar
for i in 1 2 3 4 5; do
  login "$JAR4" doctor@visioneye.et "definitely-wrong-$i"
done
if has_session "$JAR4"; then FAIL=$((FAIL+1)); echo "✗ wrong passwords must not set session"; else PASS=$((PASS+1)); echo "✓ wrong passwords set no session"; fi
state=$(sql "const u = await p.user.findUnique({ where: { email: 'doctor@visioneye.et' }, select: { failedLoginCount: true, lockedUntil: true } }); console.log(u.failedLoginCount + ':' + (u.lockedUntil ? 'locked' : 'open'));")
check "5 failures counted + locked" "5:locked" "$state"
login "$JAR4" doctor@visioneye.et "$(grep '^STAFF_PASSWORD=' .env | cut -d= -f2- | tr -d '\"')"
if has_session "$JAR4"; then FAIL=$((FAIL+1)); echo "✗ correct password rejected while locked"; else PASS=$((PASS+1)); echo "✓ correct password rejected while locked"; fi

# 10. Patients module (list, search, detail + VIEW audit, export, role gate)
code=$(curl -s -b "$JAR" -o /tmp/opencode/patients.html -w '%{http_code}' "$BASE/patients")
check "owner /patients → 200" 200 "$code"
contains "patients list title" "Patients" /tmp/opencode/patients.html
contains "seed patient row" "Almaz Tesfaye" /tmp/opencode/patients.html
contains "patient detail link" "/patients/c" /tmp/opencode/patients.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/patients-q.html -w '%{http_code}' "$BASE/patients?q=0911223344")
check "phone search → 200" 200 "$code"
contains "phone search finds the patient" "Almaz Tesfaye" /tmp/opencode/patients-q.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/patients-none.html -w '%{http_code}' "$BASE/patients?q=zzzz-no-match")
check "empty search → 200" 200 "$code"
contains "empty search state" "No results found" /tmp/opencode/patients-none.html

PID=$(sql "const row = await p.patient.findUnique({ where: { phoneE164: '+251911223344' }, select: { id: true } }); console.log(row.id);")
code=$(curl -s -b "$JAR" -o /tmp/opencode/patient.html -w '%{http_code}' "$BASE/patients/$PID")
check "owner patient detail → 200" 200 "$code"
contains "tap-to-call shows stored E.164" "+251911223344" /tmp/opencode/patient.html
contains "non-clinical notes hint" "Non-clinical notes only" /tmp/opencode/patient.html
contains "consent block" "SMS consent" /tmp/opencode/patient.html
VIEW=$(sql "const n = await p.auditLog.count({ where: { entity: 'Patient', entityId: '$PID', action: 'VIEW' } }); console.log(String(n));")
if [ "$VIEW" -ge 1 ]; then PASS=$((PASS+1)); echo "✓ detail page writes VIEW audit ($VIEW)";
else FAIL=$((FAIL+1)); echo "✗ detail page wrote no VIEW audit"; fi

code=$(curl -s -b "$JAR" -o /tmp/opencode/patients.csv -w '%{http_code}' "$BASE/api/patients/export")
check "patients CSV export → 200" 200 "$code"
contains "CSV header" "id,fullName,phone,sex,birthDate,preferredLanguage" /tmp/opencode/patients.csv
rows=$(grep -c "251" /tmp/opencode/patients.csv)
check "CSV has 20 patient rows" 20 "$rows"
code=$(curl -s -b "$JAR" -o /tmp/opencode/patients-consent.csv -w '%{http_code}' "$BASE/api/patients/export?consent=true")
check "filtered CSV export → 200" 200 "$code"
rows=$(grep -c "251" /tmp/opencode/patients-consent.csv)
check "consent filter → 11 rows" 11 "$rows"
code=$(curl -s -b "$JAR" -o /tmp/opencode/template.csv -w '%{http_code}' "$BASE/api/patients/template")
check "import template → 200" 200 "$code"
contains "template columns" "fullName,phone,sex,birthDate,tags,consent,preferredLanguage" /tmp/opencode/template.csv
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/patients/export")
check "export without session → 307 to login" 307 "$code"

# STAFF must be denied the patients module (clear the lockout from section 9 first)
sql "await p.user.update({ where: { email: 'doctor@visioneye.et' }, data: { mustChangePassword: false, failedLoginCount: 0, lockedUntil: null } }); console.log('ok');"
JAR5=/tmp/opencode/erp-smoke5.jar
login "$JAR5" doctor@visioneye.et "$(grep '^STAFF_PASSWORD=' .env | cut -d= -f2- | tr -d '\"')"
code=$(curl -s -b "$JAR5" -o /tmp/opencode/staff-patients.html -w '%{http_code}' "$BASE/patients")
check "staff /patients → 200 (flight redirect)" 200 "$code"
contains "staff /patients forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/staff-patients.html
code=$(curl -s -b "$JAR5" -o /dev/null -w '%{http_code}' "$BASE/api/patients/export")
check "staff CSV export → 403" 403 "$code"
sql "await p.user.update({ where: { email: 'doctor@visioneye.et' }, data: { mustChangePassword: true } }); console.log('restored');"

# 11. Services + Sales + Revenue
code=$(curl -s -b "$JAR" -o /tmp/opencode/services.html -w '%{http_code}' "$BASE/services")
check "owner /services → 200" 200 "$code"
contains "services list title" "Services" /tmp/opencode/services.html
contains "seeded service row" "Eye Consultation" /tmp/opencode/services.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/services-new.html -w '%{http_code}' "$BASE/services/new")
check "owner /services/new → 200" 200 "$code"
contains "new service form" "Choose a category" /tmp/opencode/services-new.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/sales.html -w '%{http_code}' "$BASE/sales")
check "owner /sales → 200" 200 "$code"
contains "sales list has receipts" "2026-0000" /tmp/opencode/sales.html
contains "sales list has total" "Total" /tmp/opencode/sales.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/sales-new.html -w '%{http_code}' "$BASE/sales/new")
check "owner /sales/new → 200" 200 "$code"
contains "new sale confirm button" "Confirm sale" /tmp/opencode/sales-new.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/voids.html -w '%{http_code}' "$BASE/sales/voids")
check "owner /sales/voids → 200" 200 "$code"

SALEID=$(sql "const row = await p.sale.findFirst({ where: { status: 'CONFIRMED' }, orderBy: { saleDate: 'desc' }, select: { id: true } }); console.log(row.id);")
code=$(curl -s -b "$JAR" -o /tmp/opencode/sale.html -w '%{http_code}' "$BASE/sales/$SALEID")
check "owner sale detail → 200" 200 "$code"
contains "sale receipt renders" "Sales receipt" /tmp/opencode/sale.html
contains "sale audit trail" "Sale created" /tmp/opencode/sale.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/revenue.html -w '%{http_code}' "$BASE/revenue")
check "owner /revenue → 200" 200 "$code"
contains "revenue total card" "Total revenue" /tmp/opencode/revenue.html
contains "revenue breakdown" "By service" /tmp/opencode/revenue.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/sales.csv -w '%{http_code}' "$BASE/api/sales/export")
check "owner sales CSV export → 200" 200 "$code"
contains "sales CSV header" "receiptNumber,saleDate,patient" /tmp/opencode/sales.csv
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/sales/export")
check "sales export without session → 307 to login" 307 "$code"

# Reception: may sell, but cannot see revenue or manage services
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-sales.html -w '%{http_code}' "$BASE/sales")
check "reception /sales → 200" 200 "$code"
contains "reception sales empty (own, today)" "Nothing here yet" /tmp/opencode/rec-sales.html
if grep -q "2026-0000" /tmp/opencode/rec-sales.html; then FAIL=$((FAIL+1)); echo "✗ reception /sales leaks other sales"; else PASS=$((PASS+1)); echo "✓ reception /sales leaks no other sales"; fi
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-revenue.html -w '%{http_code}' "$BASE/revenue")
check "reception /revenue → 200 (flight redirect)" 200 "$code"
contains "reception /revenue forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/rec-revenue.html
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-services.html -w '%{http_code}' "$BASE/services")
check "reception /services → 200 (flight redirect)" 200 "$code"
contains "reception /services forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/rec-services.html

# Staff: denied the whole sales module
code=$(curl -s -b "$JAR5" -o /tmp/opencode/staff-sales.html -w '%{http_code}' "$BASE/sales")
check "staff /sales → 200 (flight redirect)" 200 "$code"
contains "staff /sales forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/staff-sales.html
code=$(curl -s -b "$JAR5" -o /dev/null -w '%{http_code}' "$BASE/api/sales/export")
check "staff sales export → 403" 403 "$code"

# 11b. HR: schedule, leave, approvals, calendar, public on-duty feed
code=$(curl -s -b "$JAR" -o /tmp/opencode/hr-schedule.html -w '%{http_code}' "$BASE/schedule")
check "owner /schedule → 200" 200 "$code"
contains "schedule shows owner controls" "Add shift" /tmp/opencode/hr-schedule.html
contains "schedule shows seeded shift" "Dr. Tigist Alemu" /tmp/opencode/hr-schedule.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/hr-leave.html -w '%{http_code}' "$BASE/leave")
check "owner /leave → 200" 200 "$code"
contains "leave page shows balance" "Annual leave balance" /tmp/opencode/hr-leave.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/hr-inbox.html -w '%{http_code}' "$BASE/leave/inbox")
check "owner /leave/inbox → 200" 200 "$code"
contains "approval inbox lists seed request" "Hanna Girma" /tmp/opencode/hr-inbox.html
contains "approval inbox has decision form" "Approve" /tmp/opencode/hr-inbox.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/hr-cal.html -w '%{http_code}' "$BASE/leave/calendar")
check "owner /leave/calendar → 200" 200 "$code"
contains "leave calendar shows approved leave" "Dr. Tigist Alemu" /tmp/opencode/hr-cal.html

code=$(curl -s -b "$JAR2B" -o /tmp/opencode/hr-rec-schedule.html -w '%{http_code}' "$BASE/schedule")
check "reception /schedule → 200" 200 "$code"
contains "reception sees team schedule" "Dr. Tigist Alemu" /tmp/opencode/hr-rec-schedule.html
if grep -q "template-staff" /tmp/opencode/hr-rec-schedule.html; then FAIL=$((FAIL+1)); echo "✗ reception /schedule shows owner controls"; else PASS=$((PASS+1)); echo "✓ reception /schedule has no owner controls"; fi

code=$(curl -s -b "$JAR5" -o /tmp/opencode/hr-staff-schedule.html -w '%{http_code}' "$BASE/schedule")
check "staff /schedule → 200" 200 "$code"
contains "staff sees own schedule" "Dr. Abebe Kebede" /tmp/opencode/hr-staff-schedule.html
if grep -q "Dr. Tigist Alemu" /tmp/opencode/hr-staff-schedule.html; then FAIL=$((FAIL+1)); echo "✗ staff /schedule leaks teammates"; else PASS=$((PASS+1)); echo "✓ staff /schedule hides teammates"; fi
code=$(curl -s -b "$JAR5" -o /tmp/opencode/hr-staff-inbox.html -w '%{http_code}' "$BASE/leave/inbox")
check "staff /leave/inbox → 200 (flight redirect)" 200 "$code"
contains "staff approvals forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/hr-staff-inbox.html

code=$(curl -s -o /tmp/opencode/public.html -w '%{http_code}' "$BASE/api/public/doctors")
check "public doctors feed → 200" 200 "$code"
contains "feed has doctors array" "\"doctors\"" /tmp/opencode/public.html
PUB_N=$(grep -o '"specialty"' /tmp/opencode/public.html | wc -l)
check "feed publishes 3 website doctors" 3 "$PUB_N"
if grep -q -e 'annualLeaveDays' -e 'jobTitle' -e '"email"' -e '"phone"' /tmp/opencode/public.html; then FAIL=$((FAIL+1)); echo "✗ public feed leaks private fields"; else PASS=$((PASS+1)); echo "✓ public feed leaks no private fields"; fi
curl -s -D /tmp/opencode/public.headers -o /dev/null "$BASE/api/public/doctors"
contains "public feed cached for 60s" "max-age=60" /tmp/opencode/public.headers

# 11c. Appointments, website requests, follow-ups + public intake
code=$(curl -s -b "$JAR" -o /tmp/opencode/appts.html -w '%{http_code}' "$BASE/appointments")
check "owner /appointments → 200" 200 "$code"
contains "appointments title" "Appointments" /tmp/opencode/appts.html
contains "appointments list shows patient" "Fatuma Hassan" /tmp/opencode/appts.html
contains "appointments list shows doctor" "Dr. Tigist Alemu" /tmp/opencode/appts.html
contains "appointments has new button" "New appointment" /tmp/opencode/appts.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/appts-week.html -w '%{http_code}' "$BASE/appointments?view=week")
check "owner /appointments?view=week → 200" 200 "$code"
contains "week view renders patient" "Fatuma Hassan" /tmp/opencode/appts-week.html

code=$(curl -s -b "$JAR" -o /tmp/opencode/reqs.html -w '%{http_code}' "$BASE/requests")
check "owner /requests → 200" 200 "$code"
contains "requests title" "Appointment requests" /tmp/opencode/reqs.html
contains "requests shows new request" "Selam Girma" /tmp/opencode/reqs.html
contains "requests shows convert action" "Convert to appointment" /tmp/opencode/reqs.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/reqs-new.html -w '%{http_code}' "$BASE/requests?status=NEW")
check "owner /requests?status=NEW → 200" 200 "$code"
contains "NEW filter keeps NEW request" "Selam Girma" /tmp/opencode/reqs-new.html
if grep -q "Hanna Tadesse" /tmp/opencode/reqs-new.html; then FAIL=$((FAIL+1)); echo "✗ NEW filter leaks DECLINED request"; else PASS=$((PASS+1)); echo "✓ NEW filter hides DECLINED request"; fi

code=$(curl -s -b "$JAR" -o /tmp/opencode/fups.html -w '%{http_code}' "$BASE/follow-ups")
check "owner /follow-ups → 200" 200 "$code"
contains "follow-ups title" "Follow-ups" /tmp/opencode/fups.html
contains "follow-ups lists open item" "Repeat eye test" /tmp/opencode/fups.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/fups-over.html -w '%{http_code}' "$BASE/follow-ups?status=overdue")
check "owner /follow-ups?status=overdue → 200" 200 "$code"
contains "overdue tab lists overdue patient" "Bekele Wolde" /tmp/opencode/fups-over.html
if grep -q "Contact lens review" /tmp/opencode/fups-over.html; then FAIL=$((FAIL+1)); echo "✗ overdue tab leaks future follow-up"; else PASS=$((PASS+1)); echo "✓ overdue tab hides future follow-up"; fi

# seeded demo counts
AC=$(sql "const n = await p.appointment.count({ where: { id: { startsWith: 'seed-appt-' } } }); console.log(String(n));")
check "seeded appointments = 20" 20 "$AC"
RC=$(sql "const n = await p.appointmentRequest.count({ where: { id: { startsWith: 'seed-request-' } } }); console.log(String(n));")
check "seeded requests = 5" 5 "$RC"
FC=$(sql "const n = await p.followUp.count({ where: { id: { startsWith: 'seed-followup-' } } }); console.log(String(n));")
check "seeded follow-ups = 5" 5 "$FC"

# public website request intake (no session, no raw IP stored)
code=$(curl -s -o /tmp/opencode/pub-badjson.html -w '%{http_code}' -X POST "$BASE/api/public/requests" \
  -H 'Content-Type: application/json' -H "X-Forwarded-For: 10.0.0.11" --data 'not-json')
check "public request invalid JSON → 400" 400 "$code"
code=$(curl -s -o /tmp/opencode/pub-bad.html -w '%{http_code}' -X POST "$BASE/api/public/requests" \
  -H 'Content-Type: application/json' -H "X-Forwarded-For: 10.0.0.12" \
  --data '{"name":"","phone":"nope"}')
check "public request invalid fields → 422" 422 "$code"
contains "public request reports validation error" "validationError" /tmp/opencode/pub-bad.html
code=$(curl -s -o /tmp/opencode/pub-ok.html -w '%{http_code}' -X POST "$BASE/api/public/requests" \
  -H 'Content-Type: application/json' -H "X-Forwarded-For: 10.0.0.13" \
  --data '{"name":"Smoke Test","phone":"0911000001","preferredDate":"2027-01-05","language":"en"}')
check "public request valid → 200" 200 "$code"
contains "public request returns ok" "\"ok\":true" /tmp/opencode/pub-ok.html
STORED=$(sql "const row = await p.appointmentRequest.findFirst({ where: { name: 'Smoke Test' }, select: { ipHash: true } }); console.log(row ? row.ipHash : 'missing');")
if [ "$STORED" = "missing" ] || [ -z "$STORED" ]; then FAIL=$((FAIL+1)); echo "✗ public request not persisted ($STORED)"; else PASS=$((PASS+1)); echo "✓ public request persisted (ipHash only)"; fi
sql "await p.appointmentRequest.deleteMany({ where: { name: 'Smoke Test' } }); console.log('cleaned');"

# role gates: reception may view, staff is denied
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-fups.html -w '%{http_code}' "$BASE/follow-ups")
check "reception /follow-ups → 200" 200 "$code"
contains "reception sees follow-ups" "Repeat eye test" /tmp/opencode/rec-fups.html
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-appts.html -w '%{http_code}' "$BASE/appointments")
check "reception /appointments → 200" 200 "$code"
code=$(curl -s -b "$JAR5" -o /tmp/opencode/staff-appts.html -w '%{http_code}' "$BASE/appointments")
check "staff /appointments → 200 (flight redirect)" 200 "$code"
contains "staff /appointments forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/staff-appts.html
code=$(curl -s -b "$JAR5" -o /tmp/opencode/staff-reqs.html -w '%{http_code}' "$BASE/requests")
check "staff /requests → 200 (flight redirect)" 200 "$code"
contains "staff /requests forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/staff-reqs.html

# 11d. Messaging: outbox, templates, campaigns, reports, count API + job gate
code=$(curl -s -b "$JAR" -o /tmp/opencode/messages.html -w '%{http_code}' "$BASE/messages")
check "owner /messages → 200" 200 "$code"
contains "messages title" "Today's Messages" /tmp/opencode/messages.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/msg-templates.html -w '%{http_code}' "$BASE/messages/templates")
check "owner /messages/templates → 200" 200 "$code"
contains "templates title" "Message templates" /tmp/opencode/msg-templates.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/msg-campaigns.html -w '%{http_code}' "$BASE/messages/campaigns")
check "owner /messages/campaigns → 200" 200 "$code"
contains "campaigns title" "Campaigns" /tmp/opencode/msg-campaigns.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/msg-new.html -w '%{http_code}' "$BASE/messages/campaigns/new")
check "owner /messages/campaigns/new → 200" 200 "$code"
contains "new campaign title" "New campaign" /tmp/opencode/msg-new.html
code=$(curl -s -b "$JAR" -o /tmp/opencode/msg-reports.html -w '%{http_code}' "$BASE/messages/reports")
check "owner /messages/reports → 200" 200 "$code"
contains "reports title" "not sent by deadline" /tmp/opencode/msg-reports.html

TC=$(sql "const n = await p.messageTemplate.count(); console.log(String(n));")
check "seeded message templates = 26" 26 "$TC"

# count API is session-gated
code=$(curl -s -b "$JAR" -o /tmp/opencode/msg-count.html -w '%{http_code}' "$BASE/api/messages/count")
check "owner message count API → 200" 200 "$code"
contains "count API returns ok" "\"ok\":true" /tmp/opencode/msg-count.html
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/messages/count")
check "count API without session → 401" 401 "$code"

# job endpoint requires a bearer secret (disabled when CRON_SECRET is unset)
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/jobs/messages")
CRON=$(grep "^CRON_SECRET=" .env | cut -d= -f2- | tr -d '"')
if [ -z "$CRON" ]; then check "job endpoint disabled without secret → 503" 503 "$code";
else check "job endpoint rejects missing bearer → 401" 401 "$code"; fi

# reception may use messaging; staff is denied
code=$(curl -s -b "$JAR2B" -o /tmp/opencode/rec-messages.html -w '%{http_code}' "$BASE/messages")
check "reception /messages → 200" 200 "$code"
code=$(curl -s -b "$JAR5" -o /tmp/opencode/staff-messages.html -w '%{http_code}' "$BASE/messages")
check "staff /messages → 200 (flight redirect)" 200 "$code"
contains "staff /messages forbidden" "NEXT_REDIRECT;replace;/403" /tmp/opencode/staff-messages.html

# 12. Cleanup + server still alive
sql "await p.user.update({ where: { email: 'doctor@visioneye.et' }, data: { failedLoginCount: 0, lockedUntil: null } }); console.log('unlocked');"
if ss -ltn | grep -q ":3100"; then PASS=$((PASS+1)); echo "✓ server alive after full run"; else FAIL=$((FAIL+1)); echo "✗ server died during run"; fi

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
