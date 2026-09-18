<?php

namespace App\Exceptions;

use RuntimeException;

class TradingBridgeException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $safeMessage,
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct($safeMessage);
    }
}
