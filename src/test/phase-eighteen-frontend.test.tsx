import { describe, expect, it } from 'vitest'
import { phaseEighteenApi } from '../api/services'

describe('phase eighteen fleet frontend contract', () => {
  it('exposes fleet health safety flags', async () => {
    const original = globalThis.fetch
    globalThis.fetch = async () =>
      new Response(JSON.stringify({
        data: {
          phase: 18,
          fleet_version: 'BrokerFleet/v1',
          copy_trading: false,
          live_auto_exists: false,
          ai_may_route: false,
          ai_may_allocate: false,
          ai_may_change_risk: false,
          new_order_send_paths: 0,
          phase_10_sole_execution: true,
          connector: { order_send: false, routes_into_phase_10: true, duplicate_execution_engine: false },
        },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }) as Response

    try {
      const health = await phaseEighteenApi.health()
      expect(health.phase).toBe(18)
      expect(health.copy_trading).toBe(false)
      expect(health.live_auto_exists).toBe(false)
      expect(health.ai_may_route).toBe(false)
      expect((health.connector as { order_send: boolean }).order_send).toBe(false)
    } finally {
      globalThis.fetch = original
    }
  })
})
