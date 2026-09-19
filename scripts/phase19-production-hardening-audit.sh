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

echo "=== Phase 19 production hardening audit ==="
assert_present "ProductionHardening/v1" backend/app/Hardening
assert_present "RECONCILE_NOT_RETRY" backend/app/Hardening
assert_present "PHASE_10_EXECUTION_ENGINE" backend/app/Hardening
assert_present "live_auto_exists" backend/app/Hardening
assert_present "ORDER_SEND_CALL_SITES_IN_PHASE_19" backend/app/Hardening
assert_present "OpsControlCenterService" backend/app/Hardening
assert_present "AppBrokerEnvironmentService" backend/app/Hardening
assert_present "SecretInventoryService" backend/app/Hardening
assert_present "HardeningJobQueue" backend/app/Hardening
assert_present "WorkerSupervisor" backend/app/Hardening
assert_present "TradingAwareDeployService" backend/app/Hardening
assert_present "SoakChaosHarness" backend/app/Hardening

# LIVE_AUTO must not be enabled
if rg -n --glob '!**/vendor/**' --glob '!**/tests/**' -P -e "(?<![!=])=\s*['\"]LIVE_AUTO['\"]|LIVE_AUTO_ENABLED\s*=\s*true" backend/app/Hardening >/dev/null 2>&1; then
  echo "FAIL: LIVE_AUTO appears enabled in hardening code"
  fail=1
else
  echo "OK: LIVE_AUTO not enabled in hardening"
fi

if rg -n --glob '!**/vendor/**' "MetaTrader5.order_send|mt5\\.order_send" backend/app/Hardening >/dev/null 2>&1; then
  echo "FAIL: MT5 order_send in hardening layer"
  fail=1
else
  echo "OK: no MT5 order_send in hardening layer"
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
  fail=1
else
  echo "OK: no order_send outside execution.py"
fi

# Frontend must not embed credential Vite secrets
if rg -n --glob '!**/node_modules/**' -e "VITE_.*(PASSWORD|SECRET|TOKEN|API_KEY)" src >/dev/null 2>&1; then
  echo "FAIL: frontend secret-like VITE_ pattern"
  fail=1
else
  echo "OK: no frontend credential VITE_ secrets"
fi

assert_present "PHASE_19_REPORT" docs
assert_present "PHASE_20_CONTRACT" docs
assert_present "ops-control-center" src

if [[ "$fail" -ne 0 ]]; then
  echo "Phase 19 audit FAILED"
  exit 1
fi
echo "Phase 19 audit PASS"
