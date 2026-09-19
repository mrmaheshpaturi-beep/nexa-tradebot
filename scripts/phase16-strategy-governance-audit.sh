#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 16 strategy governance audit =="

# Governance + Lab must never call order_send / MetaTrader5
if rg -n "order_send\s*\(|authorized_order_send\s*\(|MetaTrader5\.|mt5\.order_send|DemoBridgeClient::|TradingBridgeDemoClient::" \
  "$ROOT/backend/app/Governance" 2>/dev/null; then
  echo "FAIL: order_send / bridge client call sites in Phase 16 Governance"
  exit 1
fi

# Sole authorized path still present exactly once
COUNT=$(rg -n "def authorized_order_send" "$ROOT/trading-engine/src/nexa_mt5/execution.py" | wc -l | tr -d ' ')
if [[ "$COUNT" != "1" ]]; then
  echo "FAIL: expected exactly 1 authorized_order_send, found $COUNT"
  exit 1
fi

# LIVE_AUTO must not exist as deploy/executable mode
if rg -n "case LiveAuto|LIVE_AUTO\s*=\s*'LIVE_AUTO'|ALLOWED_DEPLOY_TARGETS = \[.*LIVE" \
  "$ROOT/backend/app/Governance" "$ROOT/backend/app/Enums" 2>/dev/null; then
  echo "FAIL: LIVE_AUTO / LIVE deploy appears as implemented"
  exit 1
fi

# Safety markers
rg -n "ORDER_SEND_CALL_SITES_IN_PHASE_16 = 0" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "AI_MAY_APPROVE = false" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "AI_MAY_DEPLOY = false" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "AI_MAY_CHANGE_ACTIVE_CONFIG = false" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "LIVE_AUTO_EXISTS = false" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "LIVE_DEPLOY_EXISTS = false" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "ALLOWED_DEPLOY_TARGETS = \['DEMO_AUTO'\]" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "StrategyGovernanceService" "$ROOT/backend/app/Governance/StrategyGovernanceService.php" >/dev/null
rg -n "assertNotAiActor" "$ROOT/backend/app/Governance/Support/GovernanceSafety.php" >/dev/null
rg -n "LifecycleGuard" "$ROOT/backend/app/Governance/Support/LifecycleGuard.php" >/dev/null
rg -n "ConflictResolver" "$ROOT/backend/app/Governance/ConflictResolver.php" >/dev/null
rg -n "governance/dashboard" "$ROOT/backend/routes/api.php" >/dev/null

# AI approve/deploy endpoints must refuse
rg -n "refuseAiApprove|refuseLiveDeploy" "$ROOT/backend/app/Http/Controllers/Api/GovernanceController.php" >/dev/null

# Lab never deploys / mutates
if rg -n "can_deploy'\s*=>\s*true|mutates_active_config'\s*=>\s*true" \
  "$ROOT/backend/app/Governance/StrategyGovernanceService.php" 2>/dev/null; then
  echo "FAIL: Lab marked as able to deploy or mutate active config"
  exit 1
fi

# React must not expose LIVE_AUTO controls in governance UI
if rg -n "LIVE_AUTO|Enable Live Auto|live_auto" "$ROOT/src/pages/PhaseSixteenGovernancePages.tsx" 2>/dev/null; then
  # Allow explicit "does not exist" / DEMO-only banners
  if rg -n "LIVE_AUTO.*(enable|start|deploy)|Enable LIVE" "$ROOT/src/pages/PhaseSixteenGovernancePages.tsx" 2>/dev/null; then
    echo "FAIL: Governance UI exposes LIVE_AUTO controls"
    exit 1
  fi
fi

# Browser sources must not call order_send
if rg -n "order_send\s*\(" "$ROOT/src" --glob '!**/node_modules/**' 2>/dev/null; then
  echo "FAIL: browser sources reference order_send"
  exit 1
fi

# Phase 17 must remain stub only if present
if [[ -f "$ROOT/docs/PHASE_17_CONTRACT.md" ]]; then
  if ! rg -n "NOT IMPLEMENTED|stub only" "$ROOT/docs/PHASE_17_CONTRACT.md" >/dev/null; then
    echo "FAIL: Phase 17 contract missing stub markers"
    exit 1
  fi
else
  echo "FAIL: Phase 17 contract stub missing"
  exit 1
fi

echo "PASS: Phase 16 audit checks"
