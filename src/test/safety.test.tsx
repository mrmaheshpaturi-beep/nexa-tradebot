import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { EnvironmentBadge } from '../components/ui'
import { MockOrderService } from '../services/mockServices'

describe('Phase 1 safety controls', () => {
  it('renders the simulation environment prominently', () => {
    render(<EnvironmentBadge />)
    expect(screen.getByText('SIMULATION')).toBeInTheDocument()
  })

  it('never marks a simulated order as broker transmitted', async () => {
    const result = await new MockOrderService().simulateOrder({
      symbol: 'XAUUSD',
      direction: 'BUY',
      volume: 0.4,
    })
    expect(result.simulated).toBe(true)
    expect(result.accepted).toBe(true)
    expect(result.ticket).toMatch(/^SIM-/)
  })
})
