#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail=0

assert_present() {
  local pattern="$1"
  local path="$2"
  if ! rg -n --glob '!**/vendor/**' --glob '!**/node_modules/**' -e "$pattern" "$path" >/dev/null 2>&1; then
    echo "FAIL: missing required pattern '$pattern' in $path"
    fail=1
  else
    echo "OK: present '$pattern' in $path"
  fi
}

echo "=== Phase 18 broker fleet audit ==="
assert_present "BrokerFleet/v1" backend/app/Fleet
assert_present "PHASE_10_EXECUTION_ENGINE" backend/app/Fleet
assert_present "routes_into_phase_10" backend/app/Fleet
assert_present "copy_trading" backend/app/Fleet
assert_present "LIVE_AUTO does not exist" backend/app/Fleet
assert_present "live_auto_exists" backend/app/Fleet

# LIVE_AUTO must only appear as hard-reject / false existence flags — never as an enabled mode assignment
if rg -n --glob '!**/vendor/**' --glob '!**/tests/**' -P -e "(?<![!=])=\s*['\"]LIVE_AUTO['\"]|case LiveAuto|LIVE_AUTO_ENABLED\s*=\s*true" backend/app/Fleet >/dev/null 2>&1; then
  echo "FAIL: LIVE_AUTO appears enabled in fleet code"
  fail=1
else
  echo "OK: LIVE_AUTO not enabled as a fleet mode"
fi

if rg -n --glob '!**/vendor/**' "MetaTrader5.order_send|mt5\\.order_send" backend/app/Fleet >/dev/null 2>&1; then
  echo "FAIL: MT5 order_send in fleet layer"
  fail=1
else
  echo "OK: no MT5 order_send in fleet layer"
fi

count=$(rg -n "def authorized_order_send" trading-engine/src/nexa_mt5/execution.py | wc -l | tr -d ' ')
if [[ "$count" != "1" ]]; then
  echo "FAIL: expected exactly one authorized_order_send definition, got $count"
  fail=1
else
  echo "OK: sole authorized_order_send definition"
fi

if rg -n --glob '!**/vendor/**' --glob '!**/execution.py' --glob '!**/tests/**' --glob '!**/*test*.py' -e "\\.order_send\\(|mt5\\.order_send|MetaTrader5\\.order_send" trading-engine/src backend/app >/dev/null 2>&1; then
  echo "FAIL: order_send found outside execution.py"
  rg -n --glob '!**/vendor/**' --glob '!**/execution.py' --glob '!**/tests/**' --glob '!**/*test*.py' -e "\\.order_send\\(|mt5\\.order_send|MetaTrader5\\.order_send" trading-engine/src backend/app | head -20
  fail=1
else
  echo "OK: no order_send outside execution.py (tests excluded)"
fi

assert_present "PHASE_18_REPORT" docs
assert_present "PHASE_19_CONTRACT" docs
assert_present "portfolio-command-center" src

if [[ "$fail" -ne 0 ]]; then
  echo "Phase 18 audit FAILED"
  exit 1
fi
echo "Phase 18 audit PASS"
