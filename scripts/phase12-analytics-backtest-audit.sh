#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 12 analytics/backtest audit =="

# Phase 12 sources must never call order_send / demo bridge writes
if rg -n "order_send|DemoBridgeClient|TradingBridgeDemoClient|authorized_order_send|MetaTrader5" \
  "$ROOT/backend/app/Analytics" "$ROOT/backend/app/Backtest" 2>/dev/null; then
  echo "FAIL: broker-changing symbols in Phase 12 engines"
  exit 1
fi

# Laravel app must not gain new order_send call sites
if rg -n "mt5\.order_send|MetaTrader5\.order_send|\.order_send\(" \
  "$ROOT/backend/app" "$ROOT/src" --glob '!**/vendor/**' 2>/dev/null; then
  echo "FAIL: order_send found outside authorized path"
  exit 1
fi

# Sole authorized path still present
if ! rg -n "def authorized_order_send" "$ROOT/trading-engine/src/nexa_mt5/execution.py" >/dev/null; then
  echo "FAIL: authorized_order_send missing"
  exit 1
fi

# LIVE hard block markers in Phase 12
rg -n "HARD_BLOCKED|auto_promote" "$ROOT/backend/app/Analytics" "$ROOT/backend/app/Backtest" >/dev/null

# BACKTEST/DEMO separation
rg -n "BACKTEST" "$ROOT/backend/app/Backtest/BacktestEngineService.php" >/dev/null
rg -n "backtest_label|demo_label" "$ROOT/backend/app/Models/ResearchComparison.php" >/dev/null

# Promote must refuse
rg -n "refusePromote|never promotes" "$ROOT/backend/app/Http/Controllers/Api/AnalyticsBacktestController.php" >/dev/null

echo "PASS: Phase 12 audit checks"
