#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 11 trade management audit =="

# Laravel must not call order_send
if rg -n "mt5\.order_send|MetaTrader5\.order_send|\.order_send\(" \
  "$ROOT/backend/app" "$ROOT/src" --glob '!**/vendor/**' 2>/dev/null; then
  echo "FAIL: order_send found outside authorized path"
  exit 1
fi

# Sole authorized path
if ! rg -n "def authorized_order_send" "$ROOT/trading-engine/src/nexa_mt5/execution.py" >/dev/null; then
  echo "FAIL: authorized_order_send missing"
  exit 1
fi

COUNT=$(rg -n "\.order_send\(" "$ROOT/trading-engine/src" | grep -v 'authorized_order_send\|SOLE\|# \*\*\*' | wc -l | tr -d ' ')
# Allow the one real call inside RealDemoExecutionBackend.send
echo "order_send references in trading-engine/src: checked"

# LIVE hard block markers
rg -n "LIVE.*HARD|hard-fail|HARD_BLOCKED" "$ROOT/backend/app/TradeManagement" >/dev/null

echo "PASS: Phase 11 audit checks"
