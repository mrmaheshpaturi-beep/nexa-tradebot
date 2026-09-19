<?php

namespace App\Risk\Rules;

use App\Enums\RiskReasonCode;
use App\Risk\Contracts\RiskRule;
use App\Risk\RiskEvaluationContext;

final class SymbolSpecsAndQualityRule implements RiskRule
{
    public function code(): string
    {
        return 'SYMBOL_SPECS_QUALITY';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function priority(): int
    {
        return 20;
    }

    public function evaluate(RiskEvaluationContext $context): void
    {
        $instrument = $context->instrument;
        $specs = $context->symbolSpecs;
        $quote = $context->quote;

        if (! $instrument->is_enabled) {
            $context->fail(RiskReasonCode::InvalidInstrument, 'The instrument is disabled.', [], $this->code());

            return;
        }
        if ($intentStrategy = $context->intent->strategy) {
            $symbols = $intentStrategy->symbols ?? [];
            if ($symbols !== [] && ! in_array($instrument->symbol, $symbols, true)) {
                $context->fail(RiskReasonCode::InvalidInstrument, 'The instrument is not eligible for the strategy.', [
                    'strategy_symbols' => $symbols,
                ], $this->code());

                return;
            }
        }

        $required = ['digits', 'contract_size', 'minimum_volume', 'maximum_volume', 'step_volume', 'tick_size'];
        foreach ($required as $field) {
            if (! isset($specs[$field]) || $specs[$field] === null || $specs[$field] === '') {
                $context->fail(RiskReasonCode::MissingSymbolSpecs, "Missing symbol specification: {$field}.", [
                    'missing' => $field,
                ], $this->code());

                return;
            }
        }
        if ((float) $specs['contract_size'] <= 0 || (float) $specs['step_volume'] <= 0) {
            $context->fail(RiskReasonCode::MissingSymbolSpecs, 'Symbol contract size or volume step is invalid.', $specs, $this->code());

            return;
        }
        if (($quote['quality'] ?? 'UNKNOWN') === 'BAD' || ($quote['bid'] ?? 0) <= 0 || ($quote['ask'] ?? 0) <= 0) {
            $context->fail(RiskReasonCode::DataQuality, 'Quote quality is insufficient; fail closed.', [
                'quality' => $quote['quality'] ?? null,
            ], $this->code());

            return;
        }
        if (($context->accountContext['balance'] ?? 0) <= 0 && ($context->accountContext['equity'] ?? 0) <= 0) {
            $context->fail(RiskReasonCode::MissingSnapshot, 'Account equity/balance context is missing or zero.', [], $this->code());

            return;
        }

        $context->pass($this->code(), ['symbol' => $instrument->symbol, 'digits' => $specs['digits']]);
    }
}
