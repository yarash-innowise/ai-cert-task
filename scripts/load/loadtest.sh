#!/bin/sh
# Load run against the dev stack (task 44, AC-88), all inside containers; nothing is installed on the host.
#
#   LOAD_EMAIL=... LOAD_PASSWORD=... scripts/load/loadtest.sh            # 1,000 virtual users
#   LOAD_VUS=200 LOAD_EMAIL=... LOAD_PASSWORD=... scripts/load/loadtest.sh
#
# LOAD_EMAIL / LOAD_PASSWORD are a dev Super Admin created with `php bin/console user:create-super-admin ... --password-stdin`.
# The 10,000 sample users come from `php bin/console dev:seed-users 10000`. Never point this at production.
set -eu
cd "$(dirname "$0")/../.."

: "${LOAD_EMAIL:?set LOAD_EMAIL to a dev Super Admin address}"
: "${LOAD_PASSWORD:?set LOAD_PASSWORD to that Super Admin password}"

exec docker compose --profile load run --rm \
  -e LOAD_VUS="${LOAD_VUS:-1000}" \
  -e LOAD_RAMP_S="${LOAD_RAMP_S:-30}" \
  -e LOAD_HOLD_S="${LOAD_HOLD_S:-60}" \
  -e LOAD_THINK_MS="${LOAD_THINK_MS:-1500}" \
  -e LOAD_EMAIL="$LOAD_EMAIL" \
  -e LOAD_PASSWORD="$LOAD_PASSWORD" \
  load
