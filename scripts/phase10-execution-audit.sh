#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAIL=0

echo "Phase 10 execution safety audit"

# Laravel application must not call order_send directly
if rg -n "mt5\.order_send|MetaTrader5\.order_send|\.order_send\(" \
  "$ROOT/backend/app" "$ROOT/backend/routes" "$ROOT/src" >/tmp/p10-laravel-order-send.txt 2>/dev/null; then
  echo "FAIL: unexpected order_send outside authorized bridge module"
  cat /tmp/p10-laravel-order-send.txt
  FAIL=1
else
  echo "PASS: no order_send in Laravel/React app sources"
fi

# Exactly one authorized send function
COUNT=$(rg -n "def authorized_order_send" "$ROOT/trading-engine/src/nexa_mt5/execution.py" | wc -l | tr -d ' ')
if [[ "$COUNT" == "1" ]]; then
  echo "PASS: sole authorized_order_send definition"
else
  echo "FAIL: expected exactly 1 authorized_order_send, found $COUNT"
  FAIL=1
fi

# Real MetaTrader5.order_send only inside authorized path helper (RealDemoExecutionBackend.send)
REAL=$(rg -n "mt5\.order_send|self\._mt5\.order_send" "$ROOT/trading-engine/src" || true)
if echo "$REAL" | rg -q "execution\.py"; then
  OTHER=$(echo "$REAL" | rg -v "execution\.py" || true)
  if [[ -n "$OTHER" ]]; then
    echo "FAIL: order_send outside execution.py"
    echo "$OTHER"
    FAIL=1
  else
    echo "PASS: MetaTrader5 order_send confined to execution.py"
  fi
else
  echo "PASS: no additional MetaTrader5 order_send sites (mock-only path present)"
fi

# LIVE hard-fail markers
if rg -n "LIVE.*hard|hard-disabled|HARD_FAIL|hard-fail" "$ROOT/backend/app/Services/ExecutionGate.php" "$ROOT/backend/app/Execution" >/dev/null; then
  echo "PASS: LIVE hard-fail markers present"
else
  echo "FAIL: LIVE hard-fail markers missing"
  FAIL=1
fi

# Auto demo default false
if rg -n "auto_demo_execution.*=.*false" "$ROOT/backend/app/Services/SettingsService.php" >/dev/null; then
  echo "PASS: auto_demo_execution defaults false"
else
  echo "FAIL: auto_demo_execution default missing"
  FAIL=1
fi

exit "$FAIL"
