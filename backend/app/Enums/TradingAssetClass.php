<?php

namespace App\Enums;

enum TradingAssetClass: string
{
    case Forex = 'FOREX';
    case Metal = 'METAL';
    case Index = 'INDEX';
    case Crypto = 'CRYPTO';
    case Commodity = 'COMMODITY';
    case Other = 'OTHER';
    case Equity = 'EQUITY';
}
