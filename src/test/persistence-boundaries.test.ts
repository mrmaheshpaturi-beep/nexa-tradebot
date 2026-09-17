import { afterEach, describe, expect, it, vi } from 'vitest'
import { resetApiClientForTests } from '../api/client'
import { persistentServices } from '../services/persistenceServices'

afterEach(() => {
  vi.restoreAllMocks()
  resetApiClientForTests()
})

describe('Phase 2 simulation persistence boundary', () => {
  it('uses UUID command identity and sends only simulation order fields', async () => {
    const identity = persistentServices.orders.createIdentity()
    expect(identity.command_id).toMatch(/^[0-9a-f-]{36}$/)
    expect(identity.idempotency_key).toBe(`web:${identity.command_id}`)
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (path) =>
      path === '/sanctum/csrf-cookie'
        ? new Response(null, { status: 204 })
        : new Response(JSON.stringify({ data: {
          id: 1, public_id: crypto.randomUUID(), ...identity, symbol: 'XAUUSD', direction: 'BUY', volume: 0.4,
          status: 'SIMULATED', environment: 'SIMULATION', simulated: true, broker_transmitted: false, idempotent_replay: false,
        } }), { status: 201, headers: { 'Content-Type': 'application/json' } }))

    const result = await persistentServices.orders.submit({ symbol: 'XAUUSD', direction: 'BUY', volume: 0.4 }, identity)
    const request = fetchMock.mock.calls.find(([path]) => path === '/api/v1/simulation/orders')?.[1] as RequestInit
    const payload = JSON.parse(String(request.body))
    expect(payload).toMatchObject(identity)
    expect(payload).not.toHaveProperty('broker_transmitted')
    expect(payload).not.toHaveProperty('environment')
    expect(result.simulated).toBe(true)
    expect(result.broker_transmitted).toBe(false)
  })

  it('preserves one identity across a retry for backend idempotency', async () => {
    const identity = persistentServices.orders.createIdentity()
    const calls: unknown[] = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (path, init) => {
      if (path === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      calls.push(JSON.parse(String(init?.body)))
      return new Response(JSON.stringify({ data: {
        id: 1, public_id: crypto.randomUUID(), ...identity, symbol: 'EURUSD', direction: 'SELL', volume: 1,
        status: 'SIMULATED', environment: 'SIMULATION', simulated: true, broker_transmitted: false, idempotent_replay: calls.length > 1,
      } }), { status: calls.length > 1 ? 200 : 201, headers: { 'Content-Type': 'application/json' } })
    })
    await persistentServices.orders.submit({ symbol: 'EURUSD', direction: 'SELL', volume: 1 }, identity)
    const replay = await persistentServices.orders.submit({ symbol: 'EURUSD', direction: 'SELL', volume: 1 }, identity)
    expect(calls).toHaveLength(2)
    expect(calls[0]).toMatchObject(identity)
    expect(calls[1]).toMatchObject(identity)
    expect(replay.idempotent_replay).toBe(true)
  })
})
