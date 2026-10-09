#!/usr/bin/env bash
#
# Dependency vulnerability audit. Fails (exit 1) on high/critical advisories.
#
#   npm run audit:deps
#
# Output is appended to docs/security-review.md under "Dependency audit" via
# scripts/audit-deps.mjs if you pass --write; by default it only prints.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "• npm audit (high+)"
if npm audit --audit-level=high; then
  echo "✓ no high/critical advisories"
else
  echo "✗ high/critical advisories found — review before release"
  exit 1
fi