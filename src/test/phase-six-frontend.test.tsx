import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { PhaseFiveLiveCharts } from '../pages/PhaseFiveMarketPages'
import { TradingSourceProvider } from '../context/TradingSourceContext'

vi.mock('../api/services', () => ({
  marketApi: {
    snapshot: vi.fn(async () => ({
      generated_at: '2026-09-18T12:00:00Z',
      read_only: true,
      environment: 'SIMULATION',
      source: 'MOCK',
      symbols: [{ symbol: 'EURUSD', description: 'Euro', quality: { score: 100, status: 'GOOD', issues: [], usable: true }, source: 'MOCK', environment: 'SIMULATION' }],
      quotes: [],
      candles: {
        symbol: 'EURUSD',
        timeframe: 'M5',
        closed_count: 2,
        forming_count: 0,
        bars: [
          {
            symbol: 'EURUSD',
            timeframe: 'M5',
            open_time: '2026-09-18T11:50:00Z',
            open: '1.1000',
            high: '1.1010',
            low: '1.0990',
            close: '1.1005',
            tick_volume: 10,
            is_closed: true,
            quality: { score: 100, status: 'GOOD', issues: [], usable: true },
          },
          {
            symbol: 'EURUSD',
            timeframe: 'M5',
            open_time: '2026-09-18T11:55:00Z',
            open: '1.1005',
            high: '1.1020',
            low: '1.1000',
            close: '1.1015',
            tick_volume: 12,
            is_closed: true,
            quality: { score: 100, status: 'GOOD', issues: [], usable: true },
          },
        ],
      },
      summary: {
        symbol_count: 1,
        quote_count: 0,
        usable_quote_count: 0,
        stale_quote_count: 0,
        candle_count: 2,
        overall_quality_score: 90,
        overall_quality_status: 'GOOD',
        extension_hooks: { phase_6_indicator_engine: 'READY', phase_7_strategies: 'PENDING' },
      },
    })),
  },
  indicatorApi: {
    batch: vi.fn(async () => ({
      symbol: 'EURUSD',
      timeframe: 'M5',
      read_only: true,
      results: [
        {
          instrument: 'EURUSD',
          timeframe: 'M5',
          indicator: 'SMA',
          params: { period: 20 },
          overlay: true,
          timestamps_aligned_to: 'candle_open_time',
          source: 'MOCK',
          environment: 'SIMULATION',
          generated_at: '2026-09-18T12:00:00Z',
          status: 'READY',
          candle_count: 2,
          point_count: 1,
          freshness: { status: 'FRESH' },
          quality: { status: 'GOOD', score: 90, usable_for_analysis: true },
          gate: { allowed: true, reason: null },
          series: [{ time: '2026-09-18T11:55:00Z', value: '1.1010' }],
          values: { value: '1.1010' },
          read_only: true,
          execution: { order_send: false },
        },
        {
          instrument: 'EURUSD',
          timeframe: 'M5',
          indicator: 'RSI',
          params: { period: 14 },
          overlay: false,
          timestamps_aligned_to: 'candle_open_time',
          source: 'MOCK',
          environment: 'SIMULATION',
          generated_at: '2026-09-18T12:00:00Z',
          status: 'READY',
          candle_count: 2,
          point_count: 1,
          freshness: { status: 'FRESH' },
          quality: { status: 'GOOD', score: 90, usable_for_analysis: true },
          gate: { allowed: true, reason: null },
          series: [{ time: '2026-09-18T11:55:00Z', value: '55.12' }],
          values: { value: '55.12' },
          read_only: true,
          execution: { order_send: false },
        },
      ],
    })),
  },
}))

describe('Phase 6 indicator frontend', () => {
  beforeEach(() => {
    localStorage.setItem('nexa.trading-source', 'SIMULATION')
  })

  it('renders live charts indicator overlays and panel', async () => {
    render(
      <MemoryRouter>
        <TradingSourceProvider>
          <PhaseFiveLiveCharts />
        </TradingSourceProvider>
      </MemoryRouter>,
    )
    await waitFor(() => expect(screen.getByText('Indicator overlays')).toBeInTheDocument())
    expect(screen.getByText('Indicator panel')).toBeInTheDocument()
    expect(screen.getByText(/Phase 6 indicators READY/)).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('RSI')).toBeInTheDocument())
    expect(screen.getByLabelText(/market-data chart with indicators/)).toBeInTheDocument()
  })
})
