#!/usr/bin/env bash
#
# Pre-flight check for a deployment (run it ON THE HOST).
#
#   cd /httpdocs && bash scripts/preflight.sh
#
# It reports everything that is missing or wrong BEFORE the site goes live:
# the PHP version and extensions, the .env keys (never their values), the
# database connection, the migration state, the seed, the writable paths, the
# document root and the scheduler.
#
# Nothing secret is ever printed — only whether a key is set, and how long it
# is. Exit code 0 = everything is ready, 1 = at least one blocker.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

PASS=0
FAIL=0
WARN=0

ok()   { PASS=$((PASS+1)); echo "✓ $1"; }
bad()  { FAIL=$((FAIL+1)); echo "✗ $1"; }
warn() { WARN=$((WARN+1)); echo "! $1"; }

echo "• pre-flight — $(pwd)"
echo

# ── 1. PHP ────────────────────────────────────────────────────────────────────
echo "── PHP ───────────────────────────────────────────────────────────"

PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  bad "the php CLI is not on the PATH (set PHP_BIN=/path/to/php)"
  PHP_BIN=""
else
  PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
  ok "PHP $PHP_VERSION ($("$PHP_BIN" -r 'echo PHP_BINARY;'))"

  if "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    ok "PHP >= 8.3"
  else
    bad "PHP >= 8.3 is required (found $PHP_VERSION)"
  fi

  for ext in pdo_mysql bcmath mbstring openssl ctype tokenizer fileinfo json xml dom zip iconv; do
    if "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
      ok "ext-$ext"
    else
      bad "ext-$ext is missing (enable it in the domain's PHP settings)"
    fi
  done

  if "$PHP_BIN" -r 'exit(ini_get("short_open_tag") ? 1 : 0);'; then
    ok "short_open_tag is Off"
  else
    warn "short_open_tag is On — a literal XML prolog in a template would break (the app is safe, but turn it Off)"
  fi
fi

echo

# ── 2. The .env ───────────────────────────────────────────────────────────────
echo "── .env ───────────────────────────────────────────────────────────"

if [ ! -f .env ]; then
  bad ".env is missing — copy .env.example and fill it in"
else
  ok ".env exists"

  # Read a key WITHOUT printing its value.
  env_value() {
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" .env \
      | head -1 \
      | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'
  }

  env_set() {
    local value
    value="$(env_value "$1")"
    [ -n "$value" ]
  }

  # The keys that MUST have a value.
  for key in DB_DATABASE DB_USERNAME DB_PASSWORD LOGIN_SECRET_PATH \
             OWNER_EMAIL OWNER_PASSWORD RECEPTION_EMAIL RECEPTION_PASSWORD \
             STAFF_EMAIL STAFF_PASSWORD PUBLIC_BASE_URL; do
    if env_set "$key"; then
      ok "$key is set"
    else
      bad "$key is EMPTY — the app will not work until it is filled in"
    fi
  done

  # The app key.
  if env_value APP_KEY | grep -q '^base64:'; then
    ok "APP_KEY is set"
  else
    bad "APP_KEY is missing — run: php artisan key:generate"
  fi

  # A value that Dotenv cannot read (an unquoted password with a space) makes
  # the app die BEFORE Laravel starts — the web server shows a bare 500.
  if [ -n "$PHP_BIN" ]; then
    if ! ENV_PARSE_ERROR="$("$PHP_BIN" -r '
      require "vendor/autoload.php";
      $dir = sys_get_temp_dir()."/envcheck".uniqid();
      mkdir($dir);
      copy(".env", $dir."/.env");
      try {
        Dotenv\Dotenv::createImmutable($dir)->safeLoad();
      } catch (Throwable $e) {
        echo $e->getMessage();
        exit(1);
      } finally {
        @unlink($dir."/.env");
        @rmdir($dir);
      }
    ' 2>&1)"; then
      bad "the .env cannot be parsed — the site will die with a bare 500:"
      echo "$ENV_PARSE_ERROR" | head -3 | sed 's/^/      /'
      echo "      Fix it with: php scripts/env-set.php <KEY>  (it quotes the value safely)"
    else
      ok "the .env parses"
    fi

    # An unquoted value with a space makes Dotenv fail outright.
    while IFS= read -r line; do
      key="${line%%=*}"
      value="${line#*=}"
      if [[ "$value" == "'"* || "$value" == '"'* ]]; then
        continue
      fi
      if [[ "$value" == *" "* ]]; then
        warn "$key has an unquoted space — Dotenv cannot read it. Use: php scripts/env-set.php $key"
      fi
    done < <(grep -E '^[A-Z0-9_]+=' .env 2>/dev/null)
  fi

  # The secret login path: report the LENGTH only, never the value.
  SECRET="$(env_value LOGIN_SECRET_PATH)"
  if [ -z "$SECRET" ]; then
    bad "LOGIN_SECRET_PATH is empty — the site refuses to serve; set it (openssl rand -hex 12)"
  elif [ "${#SECRET}" -lt 8 ]; then
    bad "LOGIN_SECRET_PATH is shorter than 8 characters"
  elif ! printf '%s' "$SECRET" | grep -qE '^[A-Za-z0-9_-]+$'; then
    bad "LOGIN_SECRET_PATH has characters outside [A-Za-z0-9_-]"
  else
    ok "LOGIN_SECRET_PATH is valid (${#SECRET} characters, value not shown)"
  fi

  if [ "$(env_value APP_ENV)" = "production" ]; then
    ok "APP_ENV=production"
  else
    warn "APP_ENV is not production (currently: $(env_value APP_ENV))"
  fi

  if [ "$(env_value APP_DEBUG)" = "true" ]; then
    bad "APP_DEBUG=true — it must be false in production"
  else
    ok "APP_DEBUG is off"
  fi

  if [ "$(env_value SESSION_SECURE_COOKIE)" = "true" ]; then
    ok "SESSION_SECURE_COOKIE=true"
  else
    warn "SESSION_SECURE_COOKIE is not true — set it once the site is HTTPS-only"
  fi
fi

echo

# ── 3. The application ───────────────────────────────────────────────────────
echo "── Application ────────────────────────────────────────────────────"

if [ ! -f artisan ]; then
  bad "artisan is missing — is this the project root? (not public/)"
elif [ ! -d vendor ]; then
  bad "vendor/ is missing — run: composer install --no-dev --optimize-autoloader"
else
  ok "the project root (artisan + vendor/ are here)"

  if [ -z "$PHP_BIN" ]; then
    :
  elif ! BOOT_ERROR="$("$PHP_BIN" artisan --version 2>&1)"; then
    bad "the app does not boot:"
    echo "$BOOT_ERROR" | head -12 | sed 's/^/      /'
  else
    ok "the app boots"

    # The database.
    DB_ERROR=""
    if ! DB_ERROR="$("$PHP_BIN" artisan db:show --json 2>&1)"; then
      bad "the database connection FAILED — check DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD"
      echo "$DB_ERROR" | head -4 | sed 's/^/      /'
    else
      ok "the database connection works"

      # The migrations (only meaningful when the database answers).
      MIGRATIONS="$("$PHP_BIN" artisan migrate:status 2>&1)"
      if ! printf '%s' "$MIGRATIONS" | grep -q 'Migration name'; then
        bad "the migration state could not be read:"
        printf '%s\n' "$MIGRATIONS" | head -4 | sed 's/^/      /'
      else
        PENDING="$(printf '%s' "$MIGRATIONS" | grep -c 'Pending' || true)"
        if [ "$PENDING" = "0" ]; then
          ok "every migration has run"
        else
          bad "$PENDING migration(s) are pending — run: php artisan migrate --force"
        fi
      fi
    fi

    # The seed.
    SEED="$("$PHP_BIN" artisan tinker --execute="
      echo App\\Models\\Service::count().' '.App\\Models\\User::count().' '.App\\Models\\Setting::count();
    " 2>/dev/null | tail -1)"

    case "$SEED" in
      "6 3 19")
        ok "the seed is in place (6 services, 3 accounts, 19 settings)"
        ;;
      "")
        warn "could not read the seed state (skip this check)"
        ;;
      *)
        bad "the seed is incomplete (services/accounts/settings: $SEED) — run: php artisan db:seed --force"
        ;;
    esac

    # No demo data.
    PATIENTS="$("$PHP_BIN" artisan tinker --execute="echo App\\Models\\Patient::count();" 2>/dev/null | tail -1)"
    if [ "$PATIENTS" = "0" ]; then
      ok "no demo patients"
    else
      warn "$PATIENTS patient(s) in the database (not necessarily a problem)"
    fi
  fi
fi

echo

# ── 4. The paths and the web server ──────────────────────────────────────────
echo "── Paths & web server ──────────────────────────────────────────────"

for dir in storage bootstrap/cache; do
  if [ -d "$dir" ]; then
    if [ -w "$dir" ]; then
      ok "$dir is writable"
    else
      bad "$dir is NOT writable — chown it to the PHP user (chown -R <user>:<user> $dir && chmod -R 775 $dir)"
    fi
  else
    bad "$dir is missing"
  fi
done

if [ -f public/index.php ]; then
  ok "public/index.php is there"
else
  bad "public/index.php is missing"
fi

# The document root must be public/ — never the project root.
if [ -f index.php ] && [ ! -f public/index.php ]; then
  bad "the DOCUMENT ROOT looks wrong — it must point at public/, not the project root"
elif [ -f .htaccess ] && grep -qi "public" .htaccess 2>/dev/null; then
  ok "an .htaccess redirects to public/"
fi

if [ -f public/build/manifest.json ]; then
  ok "the compiled assets are in place (public/build/)"
else
  bad "public/build/ is missing — the site has no CSS/JS"
fi

echo

# ── 5. The scheduler ──────────────────────────────────────────────────────────
echo "── Scheduler ───────────────────────────────────────────────────────"

if [ -z "$PHP_BIN" ]; then
  :
elif "$PHP_BIN" artisan schedule:list 2>/dev/null | grep -q "messages:run-jobs"; then
  ok "the scheduler entry exists (run it every 5 minutes: php artisan schedule:run)"
else
  bad "the scheduler entry is missing — add a cron job: * * * * * cd <root> && php artisan schedule:run"
fi

echo
echo "• $PASS passed, $FAIL failed, $WARN warning(s)"

if [ "$FAIL" -gt 0 ]; then
  echo
  echo "• Fix the ✗ items above, then run this script again."
  exit 1
fi

exit 0
