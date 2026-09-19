<?php

namespace App\TradeManagement;

use App\Contracts\DemoBridgeClient;
use App\Enums\ManagementDecisionStatus;
use App\Enums\ManagementDecisionType;
use App\Enums\ManagementStatus;
use App\Enums\PositionOwnership;
use App\Enums\TradingEnvironment;
use App\Models\ManagedPosition;
use App\Models\ServiceHeartbeat;
use App\Models\TradeManagementDecision;
use App\Models\TradeManagementEvent;
use App\Models\TradeManagementPolicy;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Core Phase 11 engine: evaluate policy rules → decision → optional auto-execute protective DEMO actions.
 */
class TradeManagementEngineService
{
    public function __construct(
        private readonly DemoBridgeClient $bridge,
        private readonly TradeManagementRuleRegistry $registry,
        private readonly ManagementActionService $actions,
        private readonly ManagementReconciliationService $reconciliation,
        private readonly TradeSummaryService $summaries,
        private readonly PositionOwnershipService $ownership,
    ) {}

    /**
     * @param  array<string,mixed>  $overrides
     * @return array{decision:TradeManagementDecision,action:?\App\Models\PositionManagementAction}
     */
    public function evaluate(ManagedPosition $position, User $actor, array $overrides = [], bool $autoExecute = true): array
    {
        if ($position->ownership !== PositionOwnership::NexaManaged) {
            $decision = $this->recordDecision($position, $actor, [
                'decision_type' => ManagementDecisionType::Blocked->value,
                'why' => 'Foreign/manual positions are never auto-managed.',
                'rule_code' => 'OWNERSHIP',
                'priority' => 1,
            ]);

            return ['decision' => $decision, 'action' => null];
        }

        if ($position->environment !== TradingEnvironment::Demo) {
            $decision = $this->recordDecision($position, $actor, [
                'decision_type' => ManagementDecisionType::Blocked->value,
                'why' => 'Non-DEMO environment hard-blocked.',
                'rule_code' => 'ENVIRONMENT',
                'priority' => 1,
            ]);

            return ['decision' => $decision, 'action' => null];
        }

        $this->reconciliation->syncManaged($position);
        $position->refresh();

        $policy = $position->policy ?? $this->defaultPolicy($position);
        $quote = $this->bridge->freshQuote($position->symbol);
        $spec = $this->bridge->freshSymbolSpec($position->symbol);
        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];
        $mid = ($bid + $ask) / 2;
        $this->summaries->updateMaeMfe($position, $mid);
        $r = StopProtection::rMultiple(
            $position->direction,
            (float) $position->entry_price,
            $position->initial_stop_loss !== null ? (float) $position->initial_stop_loss : null,
            $position->direction->value === 'BUY' ? $bid : $ask,
        );
        if ($r !== null) {
            $position->forceFill(['r_multiple' => $r, 'floating_profit' => $overrides['floating'] ?? $position->floating_profit])->save();
        }

        $context = new ManagementContext(
            quote: $quote,
            spec: $spec,
            risk: $overrides['risk'] ?? [],
            mid: $mid,
            bid: $bid,
            ask: $ask,
            strategyInvalidated: (bool) ($overrides['strategy_invalidated'] ?? false),
            sessionClosing: (bool) ($overrides['session_closing'] ?? false),
            weekendImminent: (bool) ($overrides['weekend_imminent'] ?? false),
            emergency: (bool) ($overrides['emergency'] ?? false),
            atr: isset($overrides['atr']) ? (float) $overrides['atr'] : null,
            structureStop: isset($overrides['structure_stop']) ? (float) $overrides['structure_stop'] : null,
            extras: $overrides['extras'] ?? [],
        );

        $chosen = null;
        $ruleCode = null;
        $priority = 999;
        foreach ($this->registry->rules() as $rule) {
            if (! $rule->enabled($policy)) {
                continue;
            }
            $result = $rule->evaluate($position, $policy, $context);
            if ($result !== null) {
                $chosen = $result;
                $ruleCode = $rule->code();
                $priority = $rule->priority();
                break; // highest priority wins — avoid contradictory actions
            }
        }

        if ($chosen === null) {
            $chosen = [
                'decision_type' => ManagementDecisionType::Hold->value,
                'why' => 'No management rule fired; holding position.',
            ];
            $ruleCode = 'HOLD';
            $priority = 1000;
        }

        $decision = $this->recordDecision($position, $actor, array_merge($chosen, [
            'rule_code' => $ruleCode,
            'priority' => $priority,
            'market_snapshot' => ['quote' => $quote, 'mid' => $mid],
            'risk_snapshot' => $context->risk,
        ]));

        $action = null;
        $type = $decision->decision_type;
        $executable = ! in_array($type, [
            ManagementDecisionType::Hold,
            ManagementDecisionType::NoAction,
            ManagementDecisionType::Blocked,
        ], true);

        if ($autoExecute && $executable && ! $position->auto_management_paused) {
            $decision->forceFill(['status' => ManagementDecisionStatus::Approved])->save();
            $result = $this->actions->executeDecision(
                $decision,
                $actor,
                'auto-'.$decision->public_id,
            );
            $action = $result['action'];
        }

        $this->heartbeat();

        return ['decision' => $decision->fresh(), 'action' => $action];
    }

    public function pause(ManagedPosition $position): ManagedPosition
    {
        $position->forceFill([
            'auto_management_paused' => true,
            'management_status' => ManagementStatus::Paused,
        ])->save();
        TradeManagementEvent::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $position->user_id,
            'environment' => 'DEMO',
            'event_type' => 'AUTO_MANAGEMENT_PAUSED',
            'severity' => 'INFO',
            'payload' => [],
            'occurred_at' => now(),
        ]);

        return $position->fresh();
    }

    public function resume(ManagedPosition $position): ManagedPosition
    {
        $position->forceFill([
            'auto_management_paused' => false,
            'management_status' => ManagementStatus::Managing,
        ])->save();
        TradeManagementEvent::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $position->user_id,
            'environment' => 'DEMO',
            'event_type' => 'AUTO_MANAGEMENT_RESUMED',
            'severity' => 'INFO',
            'payload' => [],
            'occurred_at' => now(),
        ]);

        return $position->fresh();
    }

    /**
     * CLOSE ALL Nexa-managed DEMO positions — SUPER_ADMIN + confirm + DEMO verify.
     *
     * @return list<array{decision:TradeManagementDecision,action:?\App\Models\PositionManagementAction}>
     */
    public function closeAllNexaManagedDemo(User $actor, bool $confirmed): array
    {
        if (! $confirmed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'confirm' => 'CLOSE ALL requires explicit confirmation.',
            ]);
        }
        if (! $actor->roles()->where('name', 'SUPER_ADMIN')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'rbac' => 'CLOSE ALL DEMO managed positions requires SUPER_ADMIN.',
            ]);
        }

        $open = ManagedPosition::query()
            ->where('ownership', PositionOwnership::NexaManaged)
            ->where('environment', TradingEnvironment::Demo)
            ->whereNotIn('management_status', [ManagementStatus::Closed->value, ManagementStatus::ForeignIgnored->value])
            ->get();

        $out = [];
        foreach ($open as $position) {
            $decision = $this->recordDecision($position, $actor, [
                'decision_type' => ManagementDecisionType::FullClose->value,
                'why' => 'SUPER_ADMIN emergency CLOSE ALL Nexa-managed DEMO positions.',
                'rule_code' => 'EMERGENCY_CLOSE_ALL',
                'priority' => 5,
                'proposed_close_volume' => (float) $position->current_volume,
                'payload' => ['protective' => true, 'close_reason' => 'EMERGENCY_EXIT'],
            ]);
            $decision->forceFill(['status' => ManagementDecisionStatus::Approved])->save();
            $result = $this->actions->executeDecision($decision, $actor, 'close-all-'.$position->public_id.'-'.Str::ulid());
            $out[] = ['decision' => $decision->fresh(), 'action' => $result['action']];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function recordDecision(ManagedPosition $position, User $actor, array $data): TradeManagementDecision
    {
        return TradeManagementDecision::query()->create([
            'managed_position_id' => $position->id,
            'user_id' => $actor->id,
            'decision_type' => $data['decision_type'],
            'status' => ManagementDecisionStatus::Proposed,
            'rule_code' => $data['rule_code'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'why' => $data['why'] ?? null,
            'proposed_sl' => $data['proposed_sl'] ?? null,
            'proposed_tp' => $data['proposed_tp'] ?? null,
            'proposed_close_volume' => $data['proposed_close_volume'] ?? null,
            'market_snapshot' => $data['market_snapshot'] ?? null,
            'risk_snapshot' => $data['risk_snapshot'] ?? null,
            'payload' => $data['payload'] ?? [],
            'decided_at' => now(),
        ]);
    }

    private function defaultPolicy(ManagedPosition $position): TradeManagementPolicy
    {
        $policy = TradeManagementPolicy::query()->where('name', 'default')->where('is_active', true)->orderByDesc('version')->first();
        if (! $policy) {
            $policy = TradeManagementPolicy::query()->create([
                'user_id' => $position->user_id,
                'name' => 'default',
                'version' => 1,
                'is_active' => true,
                'break_even_enabled' => true,
                'break_even_trigger_type' => 'R_MULTIPLE',
                'break_even_trigger_value' => 1,
                'break_even_offset' => 0,
                'trailing_enabled' => false,
                'partial_close_enabled' => false,
                'risk_exit' => true,
                'emergency_exit' => true,
            ]);
        }
        if (! $position->management_policy_id) {
            $position->forceFill([
                'management_policy_id' => $policy->id,
                'management_policy_version' => $policy->version,
            ])->save();
        }

        return $policy;
    }

    private function heartbeat(): void
    {
        ServiceHeartbeat::query()->create([
            'service' => 'trade_management_engine',
            'instance_id' => gethostname() ?: 'local',
            'status' => 'ONLINE',
            'environment' => 'DEMO',
            'observed_at' => now(),
            'last_seen_at' => now(),
            'details' => ['phase' => 11],
        ]);
    }
}
