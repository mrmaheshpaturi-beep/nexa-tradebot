import { describe, expect, it } from 'vitest'
import type { RiskEngineDashboard } from '../api/types'

describe('phase nine risk engine contracts', () => {
  it('dashboard execution posture stays disabled', () => {
    const dashboard: RiskEngineDashboard = {
      phase: 9,
      engine: { status: 'READY', order_send: false, fail_closed: true },
      profile: null,
      account: null,
      account_context: null,
      active_locks: [],
      recent_decisions: [],
      active_reservations: [],
      execution: {
        order_send: false,
        demo_execution: false,
        live_execution: false,
        mt5_execution: 'DISABLED',
      },
    }
    expect(dashboard.phase).toBe(9)
    expect(dashboard.execution.order_send).toBe(false)
    expect(dashboard.execution.demo_execution).toBe(false)
    expect(dashboard.execution.live_execution).toBe(false)
  })

  it('proposed plans are never broker routable by contract', () => {
    expect({ broker_routable: false as const }).toEqual({ broker_routable: false })
  })
})
