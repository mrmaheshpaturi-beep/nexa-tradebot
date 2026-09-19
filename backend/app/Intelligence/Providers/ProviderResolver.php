<?php

namespace App\Intelligence\Providers;

/**
 * Resolves calendar/news/AI providers. CI/testing always get mocks unless
 * explicitly requesting UNAVAILABLE. Paid providers require env gate and
 * are never required for PASS.
 */
class ProviderResolver
{
    public function calendar(?string $name = null): CalendarProviderInterface
    {
        $name = strtoupper($name ?: $this->defaultCalendar());
        return match ($name) {
            'UNAVAILABLE' => new UnavailableCalendarProvider,
            'MOCK' => new MockCalendarProvider,
            default => $this->paidAllowed() && $name !== 'MOCK'
                ? new UnavailableCalendarProvider // paid not wired — fail closed
                : new MockCalendarProvider,
        };
    }

    public function news(?string $name = null): NewsProviderInterface
    {
        $name = strtoupper($name ?: $this->defaultNews());
        return match ($name) {
            'UNAVAILABLE' => new UnavailableNewsProvider,
            'MOCK' => new MockNewsProvider,
            default => new UnavailableNewsProvider,
        };
    }

    public function ai(?string $name = null): AIProviderInterface
    {
        $name = strtoupper($name ?: $this->defaultAi());
        // Paid third-party model providers never auto-selected in testing/CI
        if (app()->environment('testing') || ! $this->paidAllowed()) {
            return new MockAIProvider;
        }

        return match ($name) {
            'MOCK' => new MockAIProvider,
            default => new MockAIProvider, // external paid adapters not shipped — fail to mock
        };
    }

    private function paidAllowed(): bool
    {
        return filter_var(env('INTELLIGENCE_PAID_PROVIDERS', false), FILTER_VALIDATE_BOOL)
            && ! app()->environment('testing');
    }

    private function defaultCalendar(): string
    {
        return (string) env('INTELLIGENCE_CALENDAR_PROVIDER', 'MOCK');
    }

    private function defaultNews(): string
    {
        return (string) env('INTELLIGENCE_NEWS_PROVIDER', 'MOCK');
    }

    private function defaultAi(): string
    {
        return (string) env('INTELLIGENCE_AI_PROVIDER', 'MOCK');
    }
}
