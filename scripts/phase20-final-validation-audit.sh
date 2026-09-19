#!/usr/bin/env bash
# Phase 20 — Final Validation / DEMO Release Candidate audit
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
FAIL=0

ok() { echo "OK: $*"; }
fail() { echo "FAIL: $*"; FAIL=1; }

assert_present() {
  local label="$1" pattern="$2" path="$3"
  if rg -n --glob '!**/vendor/**' --glob '!**/.venv/**' --glob '!**/node_modules/**' "$pattern" "$path" >/dev/null 2>&1; then
    ok "present '$label' in $path"
  else
    fail "missing '$label' in $path"
  fi
}

echo "== Phase 20 final validation audit =="

# Sole MetaTrader5 order_send
SEND_COUNT=$(rg -n 'mt5\.order_send\(' trading-engine/src --glob '!**/.venv/**' | wc -l | tr -d ' ')
if [[ "$SEND_COUNT" == "1" ]]; then
  ok "exactly 1 mt5.order_send in trading-engine/src"
else
  fail "expected 1 mt5.order_send, found $SEND_COUNT"
fi

# No order_send in Laravel/React app sources (call sites)
if rg -n --glob '!**/vendor/**' --glob '!**/tests/**' -P '\border_send\s*\(|OrderSend\s*\(' backend/app src >/dev/null 2>&1; then
  fail "order_send call sites in backend/app or src"
else
  ok "no order_send call sites in backend/app or src"
fi

# LIVE_AUTO must not be an enabled mode
if rg -n --glob '!**/vendor/**' --glob '!**/tests/**' -P "(?<![!=])=\s*['\"]LIVE_AUTO['\"]|LIVE_AUTO_ENABLED\s*=\s*true|case LiveAuto" backend/app >/dev/null 2>&1; then
  fail "LIVE_AUTO appears enabled"
else
  ok "LIVE_AUTO not enabled as a mode"
fi

# Release identity docs
assert_present "PHASE_20_FINAL_REPORT" "PHASE_20_FINAL_REPORT|FINAL STATUS" docs/PHASE_20_FINAL_REPORT.md
assert_present "PHASE_1_TO_19_AUDIT" "PASS|PARTIAL|NOT_VERIFIED" docs/PHASE_1_TO_19_AUDIT.md
assert_present "DEFECT_REGISTER" "P0|P1|P2" docs/PHASE_20_DEFECT_REGISTER.md
assert_present "DEMO_RELEASE_CHECKLIST" "READY_FOR_CONTROLLED_DEMO|DEMO_RELEASE_CANDIDATE|NOT_READY" docs/DEMO_RELEASE_CHECKLIST.md
assert_present "RELEASE_IDENTITY" "NEXA-TRADEBOT-RC1" docs/RELEASE_IDENTITY.md

# No LIVE_READY false certification statuses in final report
if rg -n 'LIVE_READY|REAL_MONEY_READY|PROFIT_CERTIFIED' docs/PHASE_20_FINAL_REPORT.md docs/DEMO_RELEASE_CHECKLIST.md 2>/dev/null | rg -v 'DO NOT|must not|forbidden|NEVER|not create' >/dev/null 2>&1; then
  # Allow mentions that forbid them; fail only if claiming them as status
  if rg -n 'FINAL STATUS:.*(LIVE_READY|REAL_MONEY_READY|PROFIT_CERTIFIED)|status.*=.*(LIVE_READY|REAL_MONEY_READY)' docs/PHASE_20_FINAL_REPORT.md >/dev/null 2>&1; then
    fail "forbidden LIVE certification status claimed"
  else
    ok "no forbidden LIVE certification status claimed"
  fi
else
  ok "no forbidden LIVE certification statuses"
fi

# Frontend must not embed VITE secrets
if rg -n 'VITE_.*(SECRET|PASSWORD|PRIVATE|MT5)' src/ --glob '!**/test/**' >/dev/null 2>&1; then
  fail "frontend VITE secret-like env refs"
else
  ok "no frontend credential VITE_ secrets"
fi

# Version bump
if rg -n '"version": "1.0.0-rc.1"' package.json >/dev/null; then
  ok "package version 1.0.0-rc.1"
else
  fail "package version not 1.0.0-rc.1"
fi

if [[ "$FAIL" -eq 0 ]]; then
  echo "Phase 20 audit PASS"
  exit 0
fi
echo "Phase 20 audit FAIL"
exit 1
