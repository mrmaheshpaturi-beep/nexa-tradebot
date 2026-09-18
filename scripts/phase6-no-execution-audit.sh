#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAIL=0

check_absent() {
  local label="$1"
  local pattern="$2"
  local paths=("${@:3}")
  if rg -n "$pattern" "${paths[@]}" >/tmp/phase6-audit-hit.txt 2>/dev/null; then
    echo "FAIL: $label"
    cat /tmp/phase6-audit-hit.txt
    FAIL=1
  else
    echo "PASS: $label"
  fi
}

check_present() {
  local label="$1"
  local pattern="$2"
  local paths=("${@:3}")
  if rg -n "$pattern" "${paths[@]}" >/dev/null 2>&1; then
    echo "PASS: $label"
  else
    echo "FAIL: $label"
    FAIL=1
  fi
}

check_absent "order_send absent from application-owned sources" "order_send\s*\(|OrderSend\s*\(" \
  "$ROOT/trading-engine/src" "$ROOT/backend/app" "$ROOT/backend/routes" "$ROOT/src"

check_absent "indicator code does not call MT5 bridge client" "TradingBridgeClient|Mt5MarketDataProvider|/v1/market/" \
  "$ROOT/backend/app/Services/IndicatorEngineService.php" "$ROOT/backend/app/Indicators" "$ROOT/backend/app/Http/Controllers/Api/IndicatorController.php"

check_present "indicator engine consumes closed candles helper" "getClosedCandles" \
  "$ROOT/backend/app/Services/IndicatorEngineService.php"

check_present "quality gate refuse path" "REFUSED" \
  "$ROOT/backend/app/Services/IndicatorEngineService.php"

check_present "phase 6 catalog route" "indicators/catalog" \
  "$ROOT/backend/routes/api.php"

check_present "no silent MT5 mock fallback messaging" "MT5 DATA UNAVAILABLE" \
  "$ROOT/backend/app/Http/Controllers/Api/IndicatorController.php" "$ROOT/src/pages/PhaseFiveMarketPages.tsx"

exit "$FAIL"
