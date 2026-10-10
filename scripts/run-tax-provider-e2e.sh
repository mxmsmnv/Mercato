#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="${MERCATO_E2E_SITE:-/Users/mas/Sites/mercato.dev}"
BASE_URL="${MERCATO_E2E_BASE_URL:-https://mercato.test}"
ARTIFACTS="${MERCATO_E2E_ARTIFACTS:-$ROOT/artifacts/e2e-tax-providers}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/mercato-tax-e2e.XXXXXX")"
STATE="$WORK/state.json"
LOG="$WORK/emulator.jsonl"
SERVER_LOG="$ARTIFACTS/emulator-server.log"
FIXTURE="$ROOT/tests/e2e/tax-provider-fixtures.php"
ROUTER="$ROOT/tests/e2e/tax-provider-emulator.php"
EMULATOR_PID=""
SETUP_DONE=0

mkdir -p "$ARTIFACTS"
PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!$s){fwrite(STDERR,$m);exit(1);}echo (int)substr(strrchr(stream_socket_get_name($s,false),":"),1);fclose($s);')"
export MERCATO_E2E_SITE="$SITE"
export MERCATO_E2E_STATE="$STATE"
export MERCATO_E2E_BASE_URL="$BASE_URL"
export MERCATO_E2E_ARTIFACTS="$ARTIFACTS"
export MERCATO_E2E_IGNORE_HTTPS_ERRORS=1
export MERCATO_E2E_PROFILE=tax-provider
export MERCATO_TAX_EMULATOR_URL="http://127.0.0.1:$PORT"
export MERCATO_TAX_EMULATOR_LOG="$LOG"

cleanup() {
  local status=$?
  set +e
  if [[ "$SETUP_DONE" == 1 ]]; then php "$FIXTURE" cleanup
  fi
  if [[ -n "$EMULATOR_PID" ]]; then
    kill "$EMULATOR_PID" 2>/dev/null
    wait "$EMULATOR_PID" 2>/dev/null
  fi
  if [[ -f "$LOG" ]]; then cp "$LOG" "$ARTIFACTS/emulator.jsonl"; fi
  rm -rf "$WORK"
  exit "$status"
}
trap cleanup EXIT INT TERM

: > "$LOG"
php -S "127.0.0.1:$PORT" "$ROUTER" > "$SERVER_LOG" 2>&1 &
EMULATOR_PID=$!
for _ in $(seq 1 50); do
  if curl --silent --fail "$MERCATO_TAX_EMULATOR_URL/health" >/dev/null; then break; fi
  sleep 0.1
done
curl --silent --fail "$MERCATO_TAX_EMULATOR_URL/health" >/dev/null

SETUP_DONE=1
php "$FIXTURE" setup
cd "$ROOT"
npx playwright test -c tests/e2e/playwright.config.js
