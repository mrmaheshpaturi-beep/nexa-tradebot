<?php

namespace App\Risk\Rules;

use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;

final class LossAndDrawdownRule implements RiskRule
{
    public function code(): string
    {
        return 'LOSS_DRAWDOWN';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 50;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $ctx = $context->accountContext;
        $profile = $context->profile;
        $balance = max((float) $ctx['balance'], 0.00000001);

        $dailyLossPct = max(0, -1 * (float) $ctx['daily_realized_pnl']) / $balance * 100;
        if ($dailyLossPct + 0.00000001 > (float) $profile->max_daily_loss) {
            $context->fail(RiskReasonCode::DailyLossLimit, 'Daily loss limit breached.', [
                'daily_loss_percent' => round($dailyLossPct, 4),
                'max_daily_loss' => (float) $profile->max_daily_loss,
            ], $this->code());

            return;
        }

        $weeklyLossPct = max(0, -1 * (float) $ctx['weekly_realized_pnl']) / $balance * 100;
        if ($weeklyLossPct + 0.00000001 > (float) $profile->max_weekly_loss) {
            $context->fail(RiskReasonCode::WeeklyLossLimit, 'Weekly loss limit breached.', [
                'weekly_loss_percent' => round($weeklyLossPct, 4),
                'max_weekly_loss' => (float) $profile->max_weekly_loss,
            ], $this->code());

            return;
        }

        $drawdown = (float) $ctx['drawdown'];
        if ($drawdown + 0.00000001 > (float) $profile->max_drawdown) {
            $context->fail(RiskReasonCode::DrawdownLimit, 'Drawdown limit breached.', [
                'drawdown' => $drawdown,
                'max_drawdown' => (float) $profile->max_drawdown,
            ], $this->code());

            return;
        }

        if ((int) $ctx['consecutive_losses'] >= (int) $profile->max_consecutive_losses) {
            $context->fail(RiskReasonCode::ConsecutiveLossLimit, 'Consecutive loss streak limit reached.', [
                'consecutive_losses' => (int) $ctx['consecutive_losses'],
                'max_consecutive_losses' => (int) $profile->max_consecutive_losses,
            ], $this->code());

            return;
        }

        $context->pass($this->code(), [
            'daily_loss_percent' => round($dailyLossPct, 4),
            'weekly_loss_percent' => round($weeklyLossPct, 4),
            'drawdown' => $drawdown,
            'consecutive_losses' => (int) $ctx['consecutive_losses'],
        ]);
    }
}
