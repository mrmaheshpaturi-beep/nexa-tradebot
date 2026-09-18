import { describe, expect, it, vi } from 'vitest'
import { phaseEightApi } from '../api/services'

describe('phase eight scanner api client', () => {
  it('requests scanner board and run without broker fields', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.includes('/sanctum/csrf-cookie')) {
        return new Response(null, { status: 204 })
      }
      if (url.includes('/scanner/board')) {
        return new Response(JSON.stringify({
          data: {
            phase: 8,
            matrix: [],
            queue: { phase: 8, count: 0, candidates: [], disclaimer: 'not orders', execution: { order_send: false } },
            universe: { symbols: ['EURUSD'], timeframes: ['M5'], trigger_mode: 'MANUAL' },
            health: { status: 'OK' },
            execution: { order_send: false, broker_routing: false },
            disclaimer: 'candidates only',
            config: {},
            last_run: null,
          },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }
      if (url.includes('/scanner/run')) {
        expect(init?.method).toBe('POST')
        const body = JSON.parse(String(init?.body ?? '{}'))
        expect(body.trigger).toBe('MANUAL')
        expect(body.prefer).toBe('simulation')
        return new Response(JSON.stringify({
          data: {
            phase: 8,
            ok: true,
            orchestrator: { created: 0 },
            execution: { order_send: false, broker_routing: false },
          },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }
      if (url.includes('/scanner/health')) {
        return new Response(JSON.stringify({
          data: { phase: 8, status: 'OK', execution: { order_send: false } },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }
      return new Response('{}', { status: 404 })
    })
    vi.stubGlobal('fetch', fetchMock)

    const board = await phaseEightApi.board()
    expect(board.phase).toBe(8)
    expect(board.execution.order_send).toBe(false)

    const run = await phaseEightApi.run({ trigger: 'MANUAL', prefer: 'simulation' })
    expect(run.phase).toBe(8)
    expect((run.execution as { order_send: boolean }).order_send).toBe(false)

    const health = await phaseEightApi.health()
    expect(health.phase).toBe(8)

    vi.unstubAllGlobals()
  })
})
