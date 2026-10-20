#!/usr/bin/env bash
#
# Run the test suite against a real MySQL 8.0 (instead of SQLite).
#
#   scripts/mysql-test.sh              # the whole suite
#   scripts/mysql-test.sh --filter=X    # any extra phpunit argument
#
# The MySQL server is started by scripts/mysql-dev.sh; the pdo_mysql extension
# comes from the local prefix (PHP_INI_SCAN_DIR).
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

export MYSQL_HOME="${MYSQL_HOME:-/tmp/opencode/mysql}"
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-/etc/php/8.4/cli/conf.d:$(dirname "${BASH_SOURCE[0]}")/../../../../tmp/opencode/phpext/conf.d}"

# The extension directory lives outside the repo; override when it differs.
if [ -d /tmp/opencode/phpext/conf.d ]; then
  export PHP_INI_SCAN_DIR="/etc/php/8.4/cli/conf.d:/tmp/opencode/phpext/conf.d"
fi

export DB_CONNECTION=mysql
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3399}"
export DB_DATABASE="${DB_DATABASE:-vision_erp_test}"
export DB_USERNAME="${DB_USERNAME:-vision_app}"
export DB_PASSWORD="${DB_PASSWORD:-LocalDevOnly123!}"
export CACHE_STORE=array
export SESSION_DRIVER=array

bash scripts/mysql-dev.sh start >/dev/null 2>&1

php artisan config:clear >/dev/null 2>&1

php artisan test "$@"
