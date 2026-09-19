<?php

namespace App\Risk;

use App\Risk\Contracts\RiskRule;
use App\Risk\Rules\EnvironmentAndAccountRule;
use App\Risk\Rules\ExposureCorrelationRule;
use App\Risk\Rules\LossAndDrawdownRule;
use App\Risk\Rules\MarginSpreadSessionRule;
use App\Risk\Rules\RiskLockRule;
use App\Risk\Rules\StopAndRewardRiskRule;
use App\Risk\Rules\SymbolSpecsAndQualityRule;
use App\Risk\Rules\VolumeAndSizingRule;
use Illuminate\Contracts\Container\Container;

final class RiskRuleRegistry
{
    public const BUNDLE_VERSION = 'risk-rules/v1';

    public const ENGINE_VERSION = 'RiskEngine/v1';

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<RiskRule>
     */
    public function rules(): array
    {
        $classes = [
            EnvironmentAndAccountRule::class,
            RiskLockRule::class,
            SymbolSpecsAndQualityRule::class,
            VolumeAndSizingRule::class,
            StopAndRewardRiskRule::class,
            LossAndDrawdownRule::class,
            ExposureCorrelationRule::class,
            MarginSpreadSessionRule::class,
        ];

        $rules = array_map(fn (string $class): RiskRule => $this->container->make($class), $classes);
        usort($rules, fn (RiskRule $a, RiskRule $b): int => $a->priority() <=> $b->priority());

        return $rules;
    }

    /**
     * @return list<array{code:string,version:string,priority:int}>
     */
    public function catalog(): array
    {
        return array_map(fn (RiskRule $rule): array => [
            'code' => $rule->code(),
            'version' => $rule->version(),
            'priority' => $rule->priority(),
        ], $this->rules());
    }
}
