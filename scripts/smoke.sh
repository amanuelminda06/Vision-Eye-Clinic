#!/usr/bin/env bash
#
# Production smoke test against a running instance.
#
#   BASE=https://clinic.example.com bash scripts/smoke.sh
#   LOGIN_SECRET_PATH=... OWNER_EMAIL=... OWNER_PASSWORD=... bash scripts/smoke.sh
#
# Every check prints ✓ or ✗; the script exits non-zero when a check failed.
# Secrets are read from the environment (or .env) and are NEVER printed.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE="${BASE:-http://localhost:8000}"

if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env
  set +a
fi

: "${LOGIN_SECRET_PATH:?LOGIN_SECRET_PATH is not set (export it or put it in .env)}"
: "${OWNER_EMAIL:?OWNER_EMAIL is not set}"
: "${OWNER_PASSWORD:?OWNER_PASSWORD is not set}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0

check() { # check <desc> <expected> <actual>
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "✓ $1";
  else FAIL=$((FAIL+1)); echo "✗ $1 (expected: $2, got: $3)"; fi
}

contains() { # contains <desc> <needle> <file>
  if grep -qi -- "$2" "$3"; then PASS=$((PASS+1)); echo "✓ $1";
  else FAIL=$((FAIL+1)); echo "✗ $1 (missing: $2)"; fi
}

absent() { # absent <desc> <needle> <file>
  if grep -qi -- "$2" "$3"; then FAIL=$((FAIL+1)); echo "✗ $1 (unexpectedly found: $2)";
  else PASS=$((PASS+1)); echo "✓ $1"; fi
}

code() { # code <url> [jar]
  local url="$1" jar="${2:-}"
  if [ -n "$jar" ]; then
    curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$url"
  else
    curl -s -o /dev/null -w '%{http_code}' "$url"
  fi
}

get() { # get <url> <out-file> [jar]
  local url="$1" out="$2" jar="${3:-}"
  if [ -n "$jar" ]; then
    # -c as well as -b: the response may set a new session cookie.
    curl -s -b "$jar" -c "$jar" -o "$out" "$url"
  else
    curl -s -o "$out" "$url"
  fi
}

login() { # login <jar> <email> <password>
  local jar="$1" email="$2" password="$3"
  local token
  # The token must be fetched WITH the cookie jar, otherwise the session that
  # generated it is not the one that posts it (419).
  get "$BASE/login/$LOGIN_SECRET_PATH" "$WORK/login.html" "$jar"
  token="$(grep -o 'name="_token" value="[^"]*"' "$WORK/login.html" | head -1 | sed 's/.*value="//; s/"$//')"

  if [ -z "$token" ]; then
    echo "✗ could not read the CSRF token from the login page"
    exit 1
  fi

  curl -s -b "$jar" -c "$jar" -o /dev/null -X POST "$BASE/login/$LOGIN_SECRET_PATH" \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-urlencode "_token=$token" \
    --data-urlencode "email=$email" \
    --data-urlencode "password=$password"
}

echo "• smoke testing $BASE"

# ── Public pages ────────────────────────────────────────────────────────────
for path in / /about /services /doctors /book /contact /faq /privacy; do
  check "public $path → 200" 200 "$(code "$BASE$path")"
done

check "public /sitemap.xml → 200" 200 "$(code "$BASE/sitemap.xml")"
check "public /robots.txt → 200" 200 "$(code "$BASE/robots.txt")"
get "$BASE/robots.txt" "$WORK/robots.txt"
contains "robots.txt blocks /api" "/api/" "$WORK/robots.txt"
get "$BASE/sitemap.xml" "$WORK/sitemap.xml"
contains "sitemap lists /services" "/services" "$WORK/sitemap.xml"

# ── Amharic pages ───────────────────────────────────────────────────────────
# Every public page must render in Amharic (?lang=am sets the locale cookie).
#
# The expected strings are read from lang/am/*.php so a translation change
# never breaks the check (and no Ethiopic literal has to be typed by hand).
am_string() { # am_string <dotted.key>
  php -r '
    $parts = explode(".", $argv[1]);
    $data = require "lang/am/".$parts[0].".php";
    $value = $data;
    foreach (array_slice($parts, 1) as $part) {
      $value = is_array($value) ? ($value[$part] ?? null) : null;
    }
    echo is_string($value) ? $value : "";
  ' -- "$1" 2>/dev/null
}

am_page() { # am_page <path> <lang-key>
  local path="$1" key="$2"
  local needle out status

  needle="$(am_string "$key")"
  out="$WORK/am$(printf '%s' "$path" | tr '/' '_').html"

  status="$(curl -s -o "$out" -w '%{http_code}' "$BASE$path?lang=am")"

  check "amharic $path → 200" 200 "$status"

  if [ -z "$needle" ]; then
    FAIL=$((FAIL+1)); echo "✗ amharic $path renders in Amharic (the key $key is unknown)"
    return
  fi

  contains "amharic $path renders in Amharic" "$needle" "$out"
}

am_page "/" "site.meta.homeTitle"
am_page "/about" "site.meta.aboutTitle"
am_page "/services" "site.meta.servicesTitle"
am_page "/doctors" "site.meta.doctorsTitle"
am_page "/book" "site.meta.bookTitle"
am_page "/contact" "site.meta.contactTitle"
am_page "/faq" "site.meta.faqTitle"
am_page "/privacy" "site.meta.privacyTitle"

# ── Login hygiene ───────────────────────────────────────────────────────────
check "/login (no secret) → 404" 404 "$(code "$BASE/login")"
check "/login/<wrong secret> → 404" 404 "$(code "$BASE/login/not-the-secret")"
check "/dashboard signed out → 302" 302 "$(code "$BASE/dashboard")"

# ── Public APIs ─────────────────────────────────────────────────────────────
check "/api/public/doctors → 200" 200 "$(code "$BASE/api/public/doctors")"
get "$BASE/api/public/doctors" "$WORK/doctors.json"
absent "doctors feed leaks no phone" "phoneE164" "$WORK/doctors.json"

# ── ERP (signed in as the owner) ─────────────────────────────────────────────
JAR="$WORK/owner.jar"
login "$JAR" "$OWNER_EMAIL" "$OWNER_PASSWORD"

for path in /dashboard /patients /sales /appointments /follow-ups /requests /schedule /leave /leave/inbox /messages /users /staff /settings /audit /revenue /dashboard/daily /dashboard/services /dashboard/content /dashboard/feedback; do
  check "owner $path → 200" 200 "$(code "$BASE$path" "$JAR")"
done

get "$BASE/sales" "$WORK/sales.html" "$JAR"
contains "sales list renders" "Sales" "$WORK/sales.html"
contains "sales list shows a total" "Total" "$WORK/sales.html"

get "$BASE/appointments" "$WORK/appointments.html" "$JAR"
contains "appointments board renders" "Appointments" "$WORK/appointments.html"

get "$BASE/patients" "$WORK/patients.html" "$JAR"
contains "patients board renders" "Patients" "$WORK/patients.html"

get "$BASE/leave" "$WORK/leave.html" "$JAR"
contains "my leave renders" "My leave" "$WORK/leave.html"

get "$BASE/messages" "$WORK/messages.html" "$JAR"
contains "outbox renders" "Messages" "$WORK/messages.html"

get "$BASE/settings" "$WORK/settings.html" "$JAR"
contains "settings renders" "Settings" "$WORK/settings.html"

get "$BASE/audit" "$WORK/audit.html" "$JAR"
contains "audit viewer renders" "Audit" "$WORK/audit.html"

# The ERP is bilingual too.
get "$BASE/dashboard?lang=am" "$WORK/dashboard-am.html" "$JAR"
contains "amharic ERP dashboard renders" "$(am_string nav.dashboard)" "$WORK/dashboard-am.html"
contains "amharic ERP sidebar shows the requests" "$(am_string nav.requests)" "$WORK/dashboard-am.html"


# ── Exports ─────────────────────────────────────────────────────────────────
get "$BASE/patients/export?format=csv" "$WORK/patients.csv" "$JAR"
contains "patients CSV header" "id,idNo,fullName" "$WORK/patients.csv"

get "$BASE/sales/export?format=csv" "$WORK/sales.csv" "$JAR"
contains "sales CSV header" "receiptNumber,saleDate,patient" "$WORK/sales.csv"

get "$BASE/appointments/export?format=csv" "$WORK/appointments.csv" "$JAR"
contains "appointments CSV header" "id,startsAt,endsAt,patient" "$WORK/appointments.csv"

get "$BASE/leave/export?format=csv" "$WORK/leave.csv" "$JAR"
contains "leave CSV header" "id,staff,type" "$WORK/leave.csv"

get "$BASE/audit/export?format=csv" "$WORK/audit.csv" "$JAR"
contains "audit CSV header" "time,user,email" "$WORK/audit.csv"

# ── API contracts ───────────────────────────────────────────────────────────
check "message count API → 200" 200 "$(code "$BASE/api/messages/count" "$JAR")"
check "message count API signed out → 401" 401 "$(code "$BASE/api/messages/count")"

echo
echo "• $PASS passed, $FAIL failed"

if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
