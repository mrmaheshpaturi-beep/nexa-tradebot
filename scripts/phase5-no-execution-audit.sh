#!/usr/bin/env bash
set -euo pipefail
# Phase 5 historical audit — updated for post-Phase-10 sole authorized order_send path.
# Market-data phase remains read-only in its own engines; execution is confined to Phase 10.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAIL=0

check_absent() {
  local label="$1"
  local pattern="$2"
  shift 2
  if rg -n "$pattern" "$@" >/tmp/phase5-audit-hit.txt 2>/dev/null; then
    echo "FAIL: $label"
    cat /tmp/phase5-audit-hit.txt
    FAIL=1
  else
    echo "PASS: $label"
  fi
}

check_present() {
  local label="$1"
  local pattern="$2"
  shift 2
  if rg -n "$pattern" "$@" >/dev/null 2>&1; then
    echo "PASS: $label"
  else
    echo "FAIL: $label"
    FAIL=1
  fi
}

# Application-owned Laravel/React sources must not call order_send (Phase 10 owns the sole path).
check_absent "order_send absent from Laravel/React app sources" "mt5\.order_send|MetaTrader5\.order_send|\.order_send\(" \
  "$ROOT/backend/app" "$ROOT/backend/routes" "$ROOT/src"

check_absent "execution endpoint absent from bridge API" "POST.*/v1/(execute|orders|positions|trade)" \
  "$ROOT/trading-engine/src/nexa_mt5/api.py"

check_present "bridge advertises read-only contract" "read_only" \
  "$ROOT/trading-engine/src/nexa_mt5" "$ROOT/backend/app/Http/Controllers/Api/Mt5BridgeController.php"

check_present "phase 3 gate rejects non-simulation / LIVE execution" "assertCanExecute" \
  "$ROOT/backend/app/Services/ExecutionGate.php"

check_present "phase 5 market engine present" "MarketDataEngineService" \
  "$ROOT/backend/app/Services/MarketDataEngineService.php"

check_present "no silent MT5 mock fallback messaging" "MT5 DATA UNAVAILABLE" \
  "$ROOT/backend/app/Http/Controllers/Api/MarketDataController.php" "$ROOT/src/pages/PhaseFiveMarketPages.tsx"

# Confirm sole authorized path still present (Phase 10 contract carried forward)
COUNT=$(rg -n "def authorized_order_send" "$ROOT/trading-engine/src/nexa_mt5/execution.py" | wc -l | tr -d ' ')
if [[ "$COUNT" == "1" ]]; then
  echo "PASS: sole authorized_order_send definition (Phase 10)"
else
  echo "FAIL: expected exactly 1 authorized_order_send, found $COUNT"
  FAIL=1
fi

exit "$FAIL"
