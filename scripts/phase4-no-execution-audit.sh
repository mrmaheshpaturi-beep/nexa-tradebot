#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAIL=0

check_absent() {
  local label="$1"
  local pattern="$2"
  local paths=("${@:3}")
  if rg -n "$pattern" "${paths[@]}" >/tmp/phase4-audit-hit.txt 2>/dev/null; then
    echo "FAIL: $label"
    cat /tmp/phase4-audit-hit.txt
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

check_absent "order_send absent from application-owned sources" "order_send|OrderSend" \
  "$ROOT/trading-engine/src" "$ROOT/backend/app" "$ROOT/backend/routes" "$ROOT/src"

check_absent "execution endpoint absent from bridge API" "POST.*/v1/(execute|orders|positions|trade)" \
  "$ROOT/trading-engine/src/nexa_mt5/api.py"

check_present "bridge advertises read-only contract" "read_only" \
  "$ROOT/trading-engine/src/nexa_mt5" "$ROOT/backend/app/Http/Controllers/Api/Mt5BridgeController.php"

check_present "phase 3 gate rejects non-simulation execution" "isExecutable" \
  "$ROOT/backend/app/Services/ExecutionGate.php"

exit "$FAIL"
