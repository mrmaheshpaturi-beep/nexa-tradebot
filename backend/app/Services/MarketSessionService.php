<?php

namespace App\Services;

use App\Enums\TradingAssetClass;
use Carbon\Carbon;

/**
 * UTC market-session foundation (Sydney/Tokyo/London/New York).
 * Schedules are approximate UTC windows for Phase 5 status labeling only.
 */
class MarketSessionService
{
    /** @var array<string, array{start:int,end:int}> */
    private const SESSIONS = [
        'Sydney' => ['start' => 21, 'end' => 6],
        'Tokyo' => ['start' => 0, 'end' => 9],
        'London' => ['start' => 7, 'end' => 16],
        'New York' => ['start' => 12, 'end' => 21],
    ];

    /**
     * @return array{generated_at:string,sessions:list<array<string,mixed>>,active:list<string>,overlaps:list<string>}
     */
    public function sessions(?Carbon $at = null): array
    {
        $now = ($at ?? now())->utc();
        $hour = (int) $now->format('G');
        $active = [];
        $rows = [];
        foreach (self::SESSIONS as $name => $window) {
            $isActive = $this->hourInWindow($hour, $window['start'], $window['end']);
            if ($isActive) {
                $active[] = $name;
            }
            $rows[] = [
                'name' => $name,
                'timezone_basis' => 'UTC',
                'start_hour_utc' => $window['start'],
                'end_hour_utc' => $window['end'],
                'active' => $isActive,
                'dst_note' => 'Approximate UTC windows; DST-aware local calendars deferred.',
            ];
        }
        $overlaps = [];
        if (count($active) >= 2) {
            $overlaps[] = implode(' / ', $active);
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'sessions' => $rows,
            'active' => $active,
            'overlaps' => $overlaps,
        ];
    }

    /**
     * @return array{status:string,session:string|null,asset_class:string,reason:string}
     */
    public function marketStatus(string $symbol, ?string $assetClass = null, ?Carbon $at = null): array
    {
        $now = ($at ?? now())->utc();
        $class = strtoupper($assetClass ?? $this->inferAssetClass($symbol));
        if (in_array($class, ['CRYPTO', 'CRYPTOCURRENCY'], true)) {
            return [
                'status' => 'OPEN',
                'session' => 'CRYPTO_24x7',
                'asset_class' => $class,
                'reason' => 'Crypto does not follow forex weekend closure rules.',
            ];
        }

        $dow = (int) $now->dayOfWeekIso; // 1=Mon .. 7=Sun
        if ($dow === 6 || $dow === 7) {
            // Friday evening close approx 21:00 UTC through Sunday
            if ($dow === 6 || ($dow === 7 && (int) $now->format('G') < 21)) {
                return [
                    'status' => 'CLOSED',
                    'session' => null,
                    'asset_class' => $class,
                    'reason' => 'Weekend market closure (approximate UTC).',
                ];
            }
        }

        $sessions = $this->sessions($now);
        $active = $sessions['active'][0] ?? null;

        return [
            'status' => $active ? 'OPEN' : 'UNKNOWN',
            'session' => $active,
            'asset_class' => $class,
            'reason' => $active ? 'Active major session window.' : 'Outside modeled major session windows.',
        ];
    }

    public function inferAssetClass(string $symbol): string
    {
        $symbol = strtoupper($symbol);
        if (str_contains($symbol, 'BTC') || str_contains($symbol, 'ETH')) {
            return TradingAssetClass::Crypto->value;
        }
        if (str_starts_with($symbol, 'XAU') || str_starts_with($symbol, 'XAG')) {
            return 'METAL';
        }
        if (preg_match('/(NAS|US30|SPX|DAX|UK100)/', $symbol)) {
            return 'INDEX';
        }

        return 'FOREX';
    }

    private function hourInWindow(int $hour, int $start, int $end): bool
    {
        if ($start === $end) {
            return true;
        }
        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }

        return $hour >= $start || $hour < $end;
    }
}
