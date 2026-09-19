<?php

namespace App\Automation;

use App\Intelligence\Providers\MockCalendarProvider;
use App\Intelligence\Providers\UnavailableCalendarProvider;
use App\Models\AutomationProfile;

/**
 * Economic calendar trade policy — fail-closed when calendar required but unavailable.
 */
class EconomicEventTradePolicy
{
    /**
     * @return array{allowed:bool,code:?string,detail:?string,calendar_status:string}
     */
    public function evaluate(AutomationProfile $profile, string $symbol, ?array $calendarSnapshot = null): array
    {
        $policy = $profile->calendar_policy ?? [];
        $require = (bool) ($policy['require_calendar'] ?? false);
        $failClosed = (bool) ($policy['fail_closed_if_unavailable'] ?? true);

        $status = (string) ($calendarSnapshot['provider_status'] ?? $calendarSnapshot['status'] ?? 'UNAVAILABLE');
        $fabricated = (bool) ($calendarSnapshot['is_fabricated'] ?? false);
        $provider = (string) ($calendarSnapshot['provider'] ?? $calendarSnapshot['provider_status'] ?? 'NONE');

        if ($require && ($status === 'UNAVAILABLE' || $provider === 'UNAVAILABLE')) {
            if ($failClosed) {
                return [
                    'allowed' => false,
                    'code' => 'CALENDAR_UNAVAILABLE_FAIL_CLOSED',
                    'detail' => 'Calendar required by profile but unavailable.',
                    'calendar_status' => $status,
                ];
            }
        }

        // High-impact block window (when events present)
        $events = $calendarSnapshot['events'] ?? [];
        $before = (int) ($policy['block_high_impact_minutes_before'] ?? 30);
        $after = (int) ($policy['block_high_impact_minutes_after'] ?? 15);
        $now = now();
        foreach ($events as $event) {
            if (($event['impact'] ?? '') !== 'HIGH') {
                continue;
            }
            $rawAt = $event['event_at'] ?? $event['at'] ?? null;
            $at = $rawAt ? \Carbon\Carbon::parse($rawAt) : null;
            if (! $at) {
                continue;
            }
            if ($now->between($at->copy()->subMinutes($before), $at->copy()->addMinutes($after))) {
                return [
                    'allowed' => false,
                    'code' => 'HIGH_IMPACT_WINDOW',
                    'detail' => (string) ($event['title'] ?? 'high impact event'),
                    'calendar_status' => $status,
                ];
            }
        }

        return [
            'allowed' => true,
            'code' => null,
            'detail' => $fabricated ? 'Using fabricated/mock calendar (CI/dev).' : null,
            'calendar_status' => $status,
        ];
    }

    /**
     * Resolve calendar snapshot for qualification (mock in testing; fail-closed unavailable otherwise when required).
     *
     * @return array<string,mixed>
     */
    public function snapshot(bool $forceUnavailable = false): array
    {
        if ($forceUnavailable) {
            return (new UnavailableCalendarProvider)->fetch(null);
        }
        if (app()->environment('testing') || config('intelligence.calendar_provider', 'mock') === 'mock') {
            return (new MockCalendarProvider)->fetch(null);
        }

        return (new UnavailableCalendarProvider)->fetch(null);
    }
}
