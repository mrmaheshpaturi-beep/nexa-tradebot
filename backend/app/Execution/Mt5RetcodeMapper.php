<?php

namespace App\Execution;

use App\Enums\ExecutionOutcome;

/**
 * Centralized MT5 retcode classification for DEMO execution.
 */
class Mt5RetcodeMapper
{
    public const DONE = '10009';

    public const DONE_PARTIAL = '10010';

    public const REQUOTE = '10004';

    public const REJECT = '10006';

    public const CANCEL = '10007';

    public const TIMEOUT = '10012';

    public const INVALID_VOLUME = '10014';

    public const INVALID_PRICE = '10015';

    public const INVALID_STOPS = '10016';

    public const TRADE_DISABLED = '10017';

    public const MARKET_CLOSED = '10018';

    public const NO_MONEY = '10019';

    /**
     * @return array{class:string,outcome:ExecutionOutcome,retryable:bool,blind_retry:bool}
     */
    public function map(?string $retcode): array
    {
        $code = (string) $retcode;

        return match ($code) {
            self::DONE => [
                'class' => 'SUCCESS',
                'outcome' => ExecutionOutcome::Filled,
                'retryable' => false,
                'blind_retry' => false,
            ],
            self::DONE_PARTIAL => [
                'class' => 'PARTIAL',
                'outcome' => ExecutionOutcome::PartiallyFilled,
                'retryable' => false,
                'blind_retry' => false,
            ],
            self::TIMEOUT, 'UNKNOWN', '' => [
                'class' => 'UNKNOWN',
                'outcome' => ExecutionOutcome::TimeoutUnknown,
                'retryable' => false,
                'blind_retry' => false,
            ],
            self::REQUOTE => [
                'class' => 'REQUOTE',
                'outcome' => ExecutionOutcome::Rejected,
                'retryable' => true,
                'blind_retry' => false,
            ],
            self::REJECT, self::CANCEL, self::INVALID_VOLUME, self::INVALID_PRICE,
            self::INVALID_STOPS, self::TRADE_DISABLED, self::MARKET_CLOSED, self::NO_MONEY => [
                'class' => 'REJECTED',
                'outcome' => ExecutionOutcome::Rejected,
                'retryable' => false,
                'blind_retry' => false,
            ],
            default => [
                'class' => 'FAILED',
                'outcome' => ExecutionOutcome::Failed,
                'retryable' => false,
                'blind_retry' => false,
            ],
        };
    }
}
