<?php

namespace App\Risk\Contracts;

use App\Risk\RiskEvaluationContext;

interface RiskRule
{
    public function code(): string;

    public function version(): string;

    public function priority(): int;

    public function evaluate(RiskEvaluationContext $context): void;
}
