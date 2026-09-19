import { describe, expect, it } from 'vitest'
import { phaseElevenApi } from '../api/services'

describe('phase eleven trade management api client', () => {
  it('exposes management endpoints', () => {
    expect(typeof phaseElevenApi.health).toBe('function')
    expect(typeof phaseElevenApi.dashboard).toBe('function')
    expect(typeof phaseElevenApi.positions).toBe('function')
    expect(typeof phaseElevenApi.pause).toBe('function')
    expect(typeof phaseElevenApi.resume).toBe('function')
    expect(typeof phaseElevenApi.evaluate).toBe('function')
    expect(typeof phaseElevenApi.prepareClose).toBe('function')
    expect(typeof phaseElevenApi.confirmClose).toBe('function')
  })

  it('health shape documents LIVE hard blocks', async () => {
    const health = {
      live_modification: 'HARD_BLOCKED',
      order_send_location: 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
    }
    expect(health.live_modification).toBe('HARD_BLOCKED')
    expect(health.order_send_location).toContain('authorized_order_send')
  })
})
