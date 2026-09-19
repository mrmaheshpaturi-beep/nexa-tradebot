<?php

namespace App\Intelligence\Advanced;

/**
 * Packs event/news/portfolio/execution/session context for assessments.
 * Read-only — never mutates portfolio, risk, or execution state.
 */
class ContextPackEngine
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function pack(array $input): array
    {
        $calendar = is_array($input['calendar'] ?? null) ? $input['calendar'] : ['status' => 'UNAVAILABLE', 'events' => []];
        $news = is_array($input['news'] ?? null) ? $input['news'] : ['status' => 'UNAVAILABLE', 'items' => []];

        $portfolio = is_array($input['portfolio'] ?? null) ? $input['portfolio'] : [
            'status' => 'UNAVAILABLE',
            'open_positions' => 0,
            'exposure_hint' => 'UNKNOWN',
            'note' => 'Portfolio context optional — not sourced from live broker writes.',
        ];
        $portfolio['mutable'] = false;

        $execution = is_array($input['execution'] ?? null) ? $input['execution'] : [
            'status' => 'OBSERVED_ONLY',
            'last_outcome' => null,
            'phase_10_sole_authority' => true,
            'intelligence_may_submit' => false,
        ];
        $execution['order_send'] = false;
        $execution['mutable'] = false;

        $session = is_array($input['session'] ?? null) ? $input['session'] : $this->defaultSession();
        $session['mutable'] = false;

        $eventRisk = 'LOW';
        $events = $calendar['events'] ?? [];
        if (is_array($events)) {
            foreach ($events as $e) {
                $impact = strtoupper((string) ($e['impact'] ?? $e['importance'] ?? ''));
                if (in_array($impact, ['HIGH', 'CRITICAL'], true)) {
                    $eventRisk = 'HIGH';
                    break;
                }
                if ($impact === 'MEDIUM') {
                    $eventRisk = 'MEDIUM';
                }
            }
        }

        return [
            'event' => [
                'calendar' => $calendar,
                'event_risk' => $eventRisk,
            ],
            'news' => $news,
            'portfolio' => $portfolio,
            'execution' => $execution,
            'session' => $session,
            'read_only' => true,
            'mutation_tools' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function defaultSession(): array
    {
        $hour = (int) gmdate('G');
        $label = 'ASIA';
        if ($hour >= 7 && $hour < 12) {
            $label = 'LONDON';
        } elseif ($hour >= 12 && $hour < 17) {
            $label = 'NEW_YORK_OVERLAP';
        } elseif ($hour >= 17 && $hour < 22) {
            $label = 'NEW_YORK';
        }

        return [
            'status' => 'OK',
            'utc_hour' => $hour,
            'label' => $label,
            'disclaimer' => 'Session label is advisory context only.',
        ];
    }
}
