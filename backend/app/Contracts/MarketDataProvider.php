<?php

namespace App\Contracts;

use App\Enums\Timeframe;

interface MarketDataProvider
{
    /** @return array{symbol:string,bid:string,ask:string,spread:string,timestamp:string,source:string,environment:string} */
    public function getQuote(string $symbol): array;

    /** @param array<int, string> $symbols
     * @return array<string, array{symbol:string,bid:string,ask:string,spread:string,timestamp:string,source:string,environment:string}>
     */
    public function getQuotes(array $symbols): array;

    /** @return array<int, array{symbol:string,timeframe:string,open_time:string,close_time:string,open:string,high:string,low:string,close:string,tick_volume:int,source:string,environment:string}> */
    public function getCandles(string $symbol, Timeframe $timeframe, int $limit = 100): array;

    /** @return array<string, mixed> */
    public function getSymbolSpecification(string $symbol): array;
}
