#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail=0
assert_absent() {
  local pattern="$1"
  local path="$2"
  if rg -n --glob '!**/vendor/**' --glob '!**/node_modules/**' --glob '!**/*.md' -e "$pattern" "$path" >/dev/null 2>&1; then
    echo "FAIL: found forbidden pattern '$pattern' in $path"
    rg -n --glob '!**/vendor/**' --glob '!**/node_modules/**' --glob '!**/*.md' -e "$pattern" "$path" | head -20
    fail=1
  else
    echo "OK: absent '$pattern' in $path"
  fi
}

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
assert_absent "LIVE_AUTO" backend/app/Fleet
assert_absent "MetaTrader5.order_send" backend/app/Fleet
assert_absent "mt5\\.order_send" backend/app/Fleet
assert_absent "order_send\\(" backend/app/Fleet

# Sole order_send remains in trading-engine execution.py
count=$(rg -n "def authorized_order_send" trading-engine/src/nexa_mt5/execution.py | wc -l | tr -d ' ')
if [[ "$count" != "1" ]]; then
  echo "FAIL: expected exactly one authorized_order_send definition, got $count"
  fail=1
else
  echo "OK: sole authorized_order_send definition"
fi

# No new order_send sites outside execution.py
if rg -n --glob '!**/vendor/**' --glob '!**/node_modules/**' --glob '!**/execution.py' -e "\\.order_send\\(|mt5\\.order_send|MetaTrader5\\.order_send" trading-engine backend/app >/dev/null 2>&1; then
  echo "FAIL: order_send found outside execution.py"
  rg -n --glob '!**/vendor/**' --glob '!**/node_modules/**' --glob '!**/execution.py' -e "\\.order_send\\(|mt5\\.order_send|MetaTrader5\\.order_send" trading-engine backend/app | head -20
  fail=1
else
  echo "OK: no order_send outside execution.py"
fi

assert_present "PHASE_18_REPORT" docs
assert_present "PHASE_19_CONTRACT" docs
assert_present "portfolio-command-center" src

if [[ "$fail" -ne 0 ]]; then
  echo "Phase 18 audit FAILED"
  exit 1
fi
echo "Phase 18 audit PASS"
