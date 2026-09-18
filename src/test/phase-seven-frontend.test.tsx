import { describe, expect, it, vi } from 'vitest'
import { phaseSevenApi } from '../api/services'

describe('Phase 7 strategy frontend API', () => {
  it('exposes strategy engine endpoints without execution language', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({
      data: {
        phase: 7,
        plugins: [{ key: 'ema_trend', name: 'EMA Trend', category: 'TREND', description: '', evidence_family: 'TREND_EMA', default_parameters: {}, upload_allowed: false, execution: false }],
        upload_allowed: false,
        execution: { order_send: false },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)

    const catalog = await phaseSevenApi.catalog()
    expect(catalog.phase).toBe(7)
    expect(catalog.upload_allowed).toBe(false)
    expect(catalog.execution.order_send).toBe(false)
    expect(fetchMock.mock.calls[0]?.[0]).toContain('/api/v1/strategy-engine/catalog')
  })
})
