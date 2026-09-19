#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 13 trade intelligence audit =="

# Intelligence sources must never *call* order_send / demo bridge writes / risk mutation.
# Allow deny-list string constants in IntelligenceSafety.php only.
if rg -n "order_send\s*\(|DemoBridgeClient::|TradingBridgeDemoClient::|authorized_order_send\s*\(|MetaTrader5\.|new RiskEngineService|TradeManagementEngineService|mutateRisk\s*\(|promote_strategy\s*\(" \
  "$ROOT/backend/app/Intelligence" --glob '!**/Support/IntelligenceSafety.php' 2>/dev/null; then
  echo "FAIL: forbidden execution/mutation call sites in Phase 13 Intelligence"
  exit 1
fi

# No new order_send call sites in app/src
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

# Safety markers
rg -n "HARD_BLOCKED|mutation_tools|ADVISORY|SHADOW" "$ROOT/backend/app/Intelligence" >/dev/null
rg -n "FORBIDDEN_TOOL_NAMES|detectInjection" "$ROOT/backend/app/Intelligence/Support/IntelligenceSafety.php" >/dev/null
rg -n "MockAIProvider|MockNewsProvider|MockCalendarProvider" "$ROOT/backend/app/Intelligence/Providers" >/dev/null
rg -n "refuseMutation|INTELLIGENCE_HAS_NO_MUTATION_PATH" "$ROOT/backend/app/Intelligence/TradeIntelligenceEngineService.php" >/dev/null
rg -n "EvidenceLabels|MIXED_LABEL_REFUSED" "$ROOT/backend/app/Intelligence/Support/EvidenceLabels.php" >/dev/null

# Paid provider API keys / SDKs must not appear in Intelligence or its tests
if rg -n "OPENAI_API_KEY|ANTHROPIC_API_KEY|api\.openai\.com|api\.anthropic\.com" \
  "$ROOT/backend/app/Intelligence" "$ROOT/backend/tests" 2>/dev/null; then
  echo "FAIL: paid provider credentials/endpoints in Intelligence/tests"
  exit 1
fi

# Mutation refuse endpoint exists
rg -n "refuseMutate" "$ROOT/backend/app/Http/Controllers/Api/IntelligenceController.php" >/dev/null

echo "PASS: Phase 13 audit checks"
