import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { EnvironmentBadge } from '../components/ui'
import { TradingSourceProvider } from '../context/TradingSourceContext'

describe('Phase 4 MT5 read-only frontend', () => {
  it('renders simulation and MT5 source badges truthfully', () => {
    localStorage.setItem('nexa.trading-source', 'SIMULATION')
    render(<TradingSourceProvider><EnvironmentBadge /></TradingSourceProvider>)
    expect(screen.getByText('SIMULATION')).toBeInTheDocument()

    localStorage.setItem('nexa.trading-source', 'MT5_DEMO')
    render(<TradingSourceProvider><EnvironmentBadge /></TradingSourceProvider>)
    expect(screen.getByText('MT5 DEMO READ-ONLY')).toBeInTheDocument()
  })
})
