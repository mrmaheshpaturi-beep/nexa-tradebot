import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { PhaseFiveMarketWatch } from '../pages/PhaseFiveMarketPages'
import { TradingSourceProvider } from '../context/TradingSourceContext'

vi.mock('../api/services', () => ({
  marketApi: {
    snapshot: vi.fn(async () => ({
      generated_at: '2026-09-18T12:00:00Z',
      read_only: true,
      environment: 'SIMULATION',
      source: 'MOCK',
      ingestion: 'SIMULATION_PROVIDER',
      symbols: [{ symbol: 'EURUSD', description: 'Euro', quality: { score: 100, status: 'EXCELLENT', issues: [], usable: true }, source: 'MOCK', environment: 'SIMULATION' }],
      quotes: [{
        symbol: 'EURUSD',
        bid: '1.10000',
        ask: '1.10020',
        spread: '0.00020',
        timestamp: '2026-09-18T12:00:00Z',
        source: 'MOCK',
        environment: 'SIMULATION',
        freshness: { status: 'FRESH', age_seconds: 0.2, stale_after_seconds: 15, is_stale: false },
        quality: { score: 100, status: 'EXCELLENT', issues: [], usable: true },
      }],
      candles: { symbol: 'EURUSD', timeframe: 'M5', bars: [] },
      summary: {
        symbol_count: 1,
        quote_count: 1,
        usable_quote_count: 1,
        stale_quote_count: 0,
        candle_count: 0,
        overall_quality_score: 100,
        overall_quality_status: 'EXCELLENT',
        extension_hooks: { phase_6_indicator_engine: 'PENDING', phase_7_strategies: 'PENDING' },
      },
    })),
  },
}))

describe('Phase 5 market data frontend', () => {
  beforeEach(() => {
    localStorage.setItem('nexa.trading-source', 'SIMULATION')
  })

  it('renders market watch quality and freshness from snapshot', async () => {
    render(
      <MemoryRouter>
        <TradingSourceProvider>
          <PhaseFiveMarketWatch />
        </TradingSourceProvider>
      </MemoryRouter>,
    )
    await waitFor(() => expect(screen.getByText('Validated quotes')).toBeInTheDocument())
    expect(screen.getByText('EURUSD')).toBeInTheDocument()
    expect(screen.getAllByText('FRESH').length).toBeGreaterThan(0)
    expect(screen.getAllByText(/EXCELLENT/).length).toBeGreaterThan(0)
    expect(screen.getByText(/Phase 6 \/ 7 extension hooks/)).toBeInTheDocument()
  })
})
