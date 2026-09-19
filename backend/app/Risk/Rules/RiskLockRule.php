<?php

namespace App\Risk\Rules;

use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;

final class RiskLockRule implements RiskRule
{
    public function code(): string
    {
        return 'RISK_LOCK';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 15;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        if ($context->activeLocks !== []) {
            $context->fail(
                RiskReasonCode::RiskLock,
                'Active risk lock blocks new trade intents: '.implode(', ', $context->activeLocks),
                ['locks' => $context->activeLocks],
                $this->code(),
            );

            return;
        }
        $context->pass($this->code());
    }
}
