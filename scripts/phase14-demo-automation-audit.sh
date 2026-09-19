#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 14 DEMO automation audit =="

# Phase 14 Automation sources must never call order_send / MetaTrader5
if rg -n "order_send\s*\(|authorized_order_send\s*\(|MetaTrader5\.|mt5\.order_send" \
  "$ROOT/backend/app/Automation" 2>/dev/null; then
  echo "FAIL: order_send call sites in Phase 14 Automation"
  exit 1
fi

# AI/Intelligence must still never call MT5 or Demo bridge writes
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

# LIVE_AUTO must not exist as an allowed mode
if rg -n "case LiveAuto|LIVE_AUTO\s*=\s*'LIVE_AUTO'|ALLOWED_MODES = \[.*LIVE_AUTO" \
  "$ROOT/backend/app/Automation" "$ROOT/backend/app/Enums/AutomationMode.php" 2>/dev/null; then
  echo "FAIL: LIVE_AUTO appears as implemented mode"
  exit 1
fi

# Safety markers
rg -n "ORDER_SEND_CALL_SITES_IN_PHASE_14 = 0" "$ROOT/backend/app/Automation/Support/AutomationSafety.php" >/dev/null
rg -n "DEMO_AUTO|DRY_RUN" "$ROOT/backend/app/Enums/AutomationMode.php" >/dev/null
rg -n "UI_LABEL_DEMO|AUTO DEMO" "$ROOT/backend/app/Automation/Support/AutomationSafety.php" >/dev/null
rg -n "createAutomationConfirmation" "$ROOT/backend/app/Execution/ExecutionConfirmationService.php" >/dev/null
rg -n "AutomatedTradingOrchestrator" "$ROOT/backend/app/Automation/AutomatedTradingOrchestrator.php" >/dev/null
rg -n "INTELLIGENCE_WAIT|ai_authority" "$ROOT/backend/app/Automation/AutomatedCandidateQualificationEngine.php" >/dev/null
rg -n "allow_revenge_trading' => false|allow_martingale' => false" "$ROOT/backend/app/Automation/AutomationProfileService.php" >/dev/null

# React never raw MT5 payload for writes (no MetaTrader5 in src)
if rg -n "MetaTrader5|order_send\s*\(" "$ROOT/src" --glob '!**/node_modules/**' 2>/dev/null; then
  echo "FAIL: browser sources reference MT5 order_send"
  exit 1
fi

echo "PASS: Phase 14 audit checks"
