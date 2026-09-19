#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 15 observability / hardening audit =="

# Phase 15 Observability sources must never call order_send / MetaTrader5
if rg -n "order_send\s*\(|authorized_order_send\s*\(|MetaTrader5\.|mt5\.order_send" \
  "$ROOT/backend/app/Observability" 2>/dev/null; then
  echo "FAIL: order_send call sites in Phase 15 Observability"
  exit 1
fi

# AI/Intelligence must still never call MT5
if rg -n "order_send\s*\(|DemoBridgeClient::|TradingBridgeDemoClient::|authorized_order_send\s*\(|MetaTrader5\." \
  "$ROOT/backend/app/Intelligence" --glob '!**/Support/IntelligenceSafety.php' 2>/dev/null; then
  echo "FAIL: Intelligence gained execution path"
  exit 1
fi

# Sole authorized path still present exactly once
COUNT=$(rg -n "def authorized_order_send" "$ROOT/trading-engine/src/nexa_mt5/execution.py" | wc -l | tr -d ' ')
if [[ "$COUNT" != "1" ]]; then
  echo "FAIL: expected exactly 1 authorized_order_send, found $COUNT"
  exit 1
fi

# LIVE_AUTO must not exist as executable mode
if rg -n "case LiveAuto|LIVE_AUTO\s*=\s*'LIVE_AUTO'|ALLOWED_MODES = \[.*LIVE_AUTO" \
  "$ROOT/backend/app/Automation" "$ROOT/backend/app/Enums/AutomationMode.php" \
  "$ROOT/backend/app/Observability" 2>/dev/null; then
  echo "FAIL: LIVE_AUTO appears as implemented mode"
  exit 1
fi

# LIVE_PRODUCTION must not be an allowed environment
if rg -n "ALLOWED_ENVIRONMENTS = \[.*LIVE_PRODUCTION" \
  "$ROOT/backend/app/Observability/Support/ObservabilitySafety.php" 2>/dev/null; then
  echo "FAIL: LIVE_PRODUCTION in allowed environments"
  exit 1
fi

# Safety markers
rg -n "ORDER_SEND_CALL_SITES_IN_PHASE_15 = 0" "$ROOT/backend/app/Observability/Support/ObservabilitySafety.php" >/dev/null
rg -n "AI_EXECUTION_CALL_SITES = 0" "$ROOT/backend/app/Observability/Support/ObservabilitySafety.php" >/dev/null
rg -n "LIVE_AUTO_EXISTS = false" "$ROOT/backend/app/Observability/Support/ObservabilitySafety.php" >/dev/null
rg -n "RECONCILE_NOT_RETRY" "$ROOT/backend/app/Observability/Support/ObservabilitySafety.php" >/dev/null
rg -n "NO_AUTOMATIC_DECLARATION|safe_for_real_money" "$ROOT/backend/app/Observability/ObservabilityService.php" >/dev/null
rg -n "auto_disable' => false" "$ROOT/backend/app/Observability/PerformanceDriftMonitor.php" >/dev/null
rg -n "duplicates_orders' => false" "$ROOT/backend/app/Observability/SystemWatchdog.php" >/dev/null
rg -n "ObservabilityService" "$ROOT/backend/app/Observability/ObservabilityService.php" >/dev/null
rg -n "ForwardValidationService" "$ROOT/backend/app/Observability/ForwardValidationService.php" >/dev/null
rg -n "SystemHealthService" "$ROOT/backend/app/Observability/SystemHealthService.php" >/dev/null
rg -n "AlertManager" "$ROOT/backend/app/Observability/AlertManager.php" >/dev/null
rg -n "NotificationProvider" "$ROOT/backend/app/Observability/Contracts/NotificationProvider.php" >/dev/null
rg -n "trading-readiness" "$ROOT/backend/routes/api.php" >/dev/null

# CI must not claim Windows reboot executed
if rg -n "windows_reboot_claimed_executed.?=.?true|WINDOWS_REBOOT_EXECUTED\s*=\s*true" \
  "$ROOT/backend/app/Observability" "$ROOT/scripts" 2>/dev/null; then
  echo "FAIL: falsely claiming Windows reboot executed"
  exit 1
fi

# React never raw MT5 payload for writes
if rg -n "MetaTrader5|order_send\s*\(" "$ROOT/src" --glob '!**/node_modules/**' 2>/dev/null; then
  echo "FAIL: browser sources reference MT5 order_send"
  exit 1
fi

# Phase 16 must remain stub only if present
if [[ -f "$ROOT/docs/PHASE_16_CONTRACT.md" ]]; then
  if ! rg -n "NOT IMPLEMENTED|stub only" "$ROOT/docs/PHASE_16_CONTRACT.md" >/dev/null; then
    echo "FAIL: Phase 16 contract missing stub markers"
    exit 1
  fi
fi

echo "PASS: Phase 15 audit checks"
