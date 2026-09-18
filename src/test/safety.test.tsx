import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { EnvironmentBadge } from '../components/ui'
import { TradingSourceProvider } from '../context/TradingSourceContext'
import { MockOrderService } from '../services/mockServices'

describe('Phase 1 safety controls', () => {
  it('renders the simulation environment prominently', () => {
    localStorage.setItem('nexa.trading-source', 'SIMULATION')
    render(<TradingSourceProvider><EnvironmentBadge /></TradingSourceProvider>)
    expect(screen.getByText('SIMULATION')).toBeInTheDocument()
  })

  it('never marks a simulated order as broker transmitted', async () => {
    const result = await new MockOrderService().simulateOrder({
      symbol: 'XAUUSD',
      direction: 'BUY',
      orderType: 'Market',
      volume: 0.4,
      riskPercent: 1,
      comment: 'Test simulation',
    })
    expect(result.simulated).toBe(true)
    expect(result.accepted).toBe(true)
    expect(result.ticket).toMatch(/^SIM-/)
    expect(result.input.symbol).toBe('XAUUSD')
  })

  it('rejects unsafe simulation order inputs', async () => {
    const service = new MockOrderService()
    await expect(service.simulateOrder({
      symbol: 'XAUUSD',
      direction: 'BUY',
      orderType: 'Market',
      volume: 20,
      riskPercent: 5,
      comment: '',
    })).rejects.toThrow('Volume must be between')
  })
})
