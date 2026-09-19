#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== Phase 17 advanced intelligence audit =="

# Advanced Intelligence must never call order_send / demo bridge writes / risk / approval / deployment mutation.
if rg -n "order_send\s*\(|DemoBridgeClient::|TradingBridgeDemoClient::|authorized_order_send\s*\(|MetaTrader5\.|new RiskEngineService|TradeManagementEngineService|mutateRisk\s*\(|promote_strategy\s*\(|GovernanceApproval|startApproval\s*\(|consumeApprovalStep\s*\(" \
  "$ROOT/backend/app/Intelligence" --glob '!**/Support/IntelligenceSafety.php' 2>/dev/null; then
    echo "FAIL: forbidden execution/mutation call sites in Intelligence"
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

# LIVE_AUTO must not exist as an enabled mode
if rg -n "LIVE_AUTO\s*=\s*True|mode.*=.*['\"]LIVE_AUTO['\"]" "$ROOT/backend/app/Intelligence" 2>/dev/null; then
    echo "FAIL: LIVE_AUTO enabled in Intelligence"
    exit 1
fi

# Orchestrator extends Phase 13
rg -n "AdvancedIntelligence/v1|extends_phase_13|TradeIntelligenceEngineService" \
  "$ROOT/backend/app/Intelligence/Advanced/AdvancedIntelligenceOrchestrator.php" >/dev/null
rg -n "ORCHESTRATOR_VERSION|PROMPT_VERSION_V2|PHASE_16_GOVERNANCE_MANDATORY" \
  "$ROOT/backend/app/Intelligence/Support/IntelligenceSafety.php" >/dev/null
rg -n "MockAIProvider|MockNewsProvider|MockCalendarProvider" "$ROOT/backend/app/Intelligence/Providers" >/dev/null
rg -n "refuseMutation|ADVANCED_INTELLIGENCE_HAS_NO_MUTATION_PATH" \
  "$ROOT/backend/app/Intelligence/Advanced/AdvancedIntelligenceOrchestrator.php" >/dev/null
rg -n "lookahead_safe|SAMPLE_GUARD" \
  "$ROOT/backend/app/Intelligence/Advanced/HistoricalAnalogEngine.php" >/dev/null
rg -n "ai_influences_score|separation_enforced" \
  "$ROOT/backend/app/Intelligence/Advanced/DeterministicScoringSeparator.php" >/dev/null

# Paid provider API keys / SDKs must not appear in Intelligence or its tests
if rg -n "OPENAI_API_KEY|ANTHROPIC_API_KEY|api\.openai\.com|api\.anthropic\.com" \
  "$ROOT/backend/app/Intelligence" "$ROOT/backend/tests/Feature/PhaseSeventeenAdvancedIntelligenceTest.php" 2>/dev/null; then
    echo "FAIL: paid provider credentials/endpoints in Intelligence/Phase 17 tests"
    exit 1
fi

# Mutation refuse endpoint exists
rg -n "refuseMutate" "$ROOT/backend/app/Http/Controllers/Api/AdvancedIntelligenceController.php" >/dev/null

# Phase 18 must remain stub only if present
if [[ -f "$ROOT/docs/PHASE_18_CONTRACT.md" ]]; then
  if ! rg -n "NOT IMPLEMENTED|stub only" "$ROOT/docs/PHASE_18_CONTRACT.md" >/dev/null; then
    echo "FAIL: PHASE_18_CONTRACT.md must remain stub-only"
    exit 1
  fi
fi

echo "PASS: Phase 17 audit checks"
