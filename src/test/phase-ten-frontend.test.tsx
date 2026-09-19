import { describe, expect, it } from 'vitest'
import { phaseTenApi } from '../api/services'

describe('Phase 10 execution API client', () => {
  it('exposes DEMO execution endpoints', () => {
    expect(typeof phaseTenApi.health).toBe('function')
    expect(typeof phaseTenApi.dashboard).toBe('function')
    expect(typeof phaseTenApi.startConfirmation).toBe('function')
    expect(typeof phaseTenApi.completeConfirmation).toBe('function')
    expect(typeof phaseTenApi.submit).toBe('function')
    expect(typeof phaseTenApi.recover).toBe('function')
    expect(typeof phaseTenApi.reconcile).toBe('function')
  })

  it('documents DEMO safety defaults in health shape expectations', () => {
    const health = {
      phase: 10,
      live_execution: 'HARD_FAIL',
      auto_demo_execution: false,
      order_send_location: 'trading-engine/src/nexa_mt5/execution.py::authorized_order_send',
    }
    expect(health.auto_demo_execution).toBe(false)
    expect(health.live_execution).toBe('HARD_FAIL')
    expect(health.order_send_location).toContain('authorized_order_send')
  })
})
