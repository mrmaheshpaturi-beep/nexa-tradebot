import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { makePhaseThreeIdentity, phaseThreeApi } from '../api/services'
import { resetApiClientForTests } from '../api/client'
import { LifecycleDistinctions, PersistentManualTrading } from '../pages/PhaseThreeTradingPages'
import type { ExecutionCommand, TradeIntent } from '../api/types'

let allowPermissions = true
vi.mock('../auth/authState', () => ({
  useAuth: () => ({ can: () => allowPermissions, user: null }),
}))

const json = (data: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify({ data }), {
  status, headers: { 'Content-Type': 'application/json' },
}))

beforeEach(() => { allowPermissions = true })
afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  resetApiClientForTests()
})

describe('Phase 3 typed API boundary', () => {
  it('uses exact action paths, payloads, and caller-provided idempotency keys', async () => {
    const calls: Array<[string, RequestInit | undefined]> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (path, init) => {
      if (path === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      calls.push([String(path), init])
      return json({ public_id: 'SIM-CMD-1', status: 'COMPLETED' })
    })

    await phaseThreeApi.executeTradeIntent('SIM-INT-1', 'execute-key')
    await phaseThreeApi.partialClosePosition('SIM-POS-1', .2, 'partial-key')
    await phaseThreeApi.modifyStopLoss('SIM-POS-1', 99.5, 'sl-key')
    await phaseThreeApi.cancelOrder('SIM-ORD-1', 'cancel-key')

    expect(calls.map(([path]) => path)).toEqual([
      '/api/v1/trade-intents/SIM-INT-1/execute',
      '/api/v1/positions/SIM-POS-1/partial-close',
      '/api/v1/positions/SIM-POS-1/stop-loss',
      '/api/v1/orders/SIM-ORD-1/cancel',
    ])
    expect(JSON.parse(String(calls[0][1]?.body))).toEqual({ idempotency_key: 'execute-key' })
    expect(JSON.parse(String(calls[1][1]?.body))).toEqual({ volume: .2, idempotency_key: 'partial-key' })
    expect(JSON.parse(String(calls[2][1]?.body))).toEqual({ stop_loss: 99.5, idempotency_key: 'sl-key' })
    expect(JSON.parse(String(calls[3][1]?.body))).toEqual({ idempotency_key: 'cancel-key' })
    expect(makePhaseThreeIdentity('intent')).toMatch(/^intent:web:[0-9a-f-]{36}$/)
  })

  it('sends public account/instrument identities without forbidden execution fields', async () => {
    let body: Record<string, unknown> = {}
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (path, init) => {
      if (path === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      body = JSON.parse(String(init?.body))
      return json({ public_id: 'SIM-INT-1', status: 'PENDING_RISK' })
    })
    await phaseThreeApi.createTradeIntent({
      account_public_id: '11111111-1111-4111-8111-111111111111',
      instrument_public_id: '22222222-2222-4222-8222-222222222222',
      idempotency_key: 'intent-key', side: 'BUY', order_type: 'MARKET', requested_volume: .1,
    })
    expect(body).toMatchObject({ idempotency_key: 'intent-key', side: 'BUY', order_type: 'MARKET' })
    expect(body).not.toHaveProperty('environment')
    expect(body).not.toHaveProperty('broker_transmitted')
  })
})

describe('Manual simulation gates', () => {
  it('defaults to EURUSD and renders backend mock quote protection guidance', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (path) => {
      if (path === '/api/v1/broker-accounts') return json({ current_page: 1, last_page: 1, per_page: 15, total: 1, data: [{ public_id: 'account', name: 'Simulation Account', environment: 'SIMULATION' }] })
      return json({
        current_page: 1, last_page: 1, per_page: 15, total: 2, data: [
          {
            public_id: 'btc', symbol: 'BTCUSD', display_name: 'Bitcoin', is_enabled: true,
            digits: 2, tick_size: '0.0100000000', minimum_volume: '0.0100', step_volume: '0.0100',
            minimum_stop_distance: '0.0000000000',
            mock_quote: { symbol: 'BTCUSD', bid: '60000.00', ask: '60010.00', spread: '10', timestamp: '2026-09-17T12:00:00Z', source: 'MOCK', environment: 'SIMULATION' },
          },
          {
            public_id: 'eur', symbol: 'EURUSD', display_name: 'Euro / US Dollar', is_enabled: true,
            digits: 5, tick_size: '0.0000100000', minimum_volume: '0.0100', step_volume: '0.0100',
            minimum_stop_distance: '0.0000000000',
            mock_quote: { symbol: 'EURUSD', bid: '1.10000', ask: '1.10020', spread: '0.0002', timestamp: '2026-09-17T12:00:00Z', source: 'MOCK', environment: 'SIMULATION' },
          },
        ],
      })
    })

    render(<PersistentManualTrading />)

    expect(await screen.findByLabelText('Persisted instrument')).toHaveValue('eur')
    expect(screen.getByText('1.10000 / 1.10020')).toBeInTheDocument()
    expect(screen.getByText('BUY requires SL < 1.10020 and TP > 1.10020 (ask mock entry).')).toBeInTheDocument()
    expect(screen.getByLabelText('Stop loss')).toHaveAttribute('step', '0.0000100000')
  })

  it('does not execute when deterministic risk rejects the persisted intent', async () => {
    const paths: string[] = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (path) => {
      const route = String(path)
      paths.push(route)
      if (route === '/api/v1/broker-accounts') return json({ current_page: 1, last_page: 1, per_page: 15, total: 1, data: [{ id: 1, public_id: '11111111-1111-4111-8111-111111111111', name: 'Simulation Account', environment: 'SIMULATION' }] })
      if (route === '/api/v1/instruments') return json({ current_page: 1, last_page: 1, per_page: 15, total: 1, data: [{ id: 1, public_id: '22222222-2222-4222-8222-222222222222', symbol: 'XAUUSD', display_name: 'Gold', is_enabled: true }] })
      if (route === '/sanctum/csrf-cookie') return new Response(null, { status: 204 })
      if (route === '/api/v1/trade-intents') return json({ public_id: 'SIM-INT-REJECT', status: 'PENDING_RISK', created_at: '2026-09-17T12:00:00Z' }, 201)
      if (route === '/api/v1/trade-intents/SIM-INT-REJECT/evaluate') return json({
        public_id: 'SIM-INT-REJECT', status: 'RISK_REJECTED',
        risk_decision: { public_id: 'SIM-RISK-1', status: 'REJECTED', reason: 'Emergency stop is active.' },
      })
      throw new Error(`Unexpected request ${route}`)
    })

    render(<PersistentManualTrading />)
    const simulate = await screen.findByRole('button', { name: 'SIMULATE BUY' })
    fireEvent.click(simulate)
    fireEvent.click(screen.getByRole('button', { name: 'Confirm simulation action' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Emergency stop is active')
    expect(paths.some((path) => path.endsWith('/execute'))).toBe(false)
    expect(screen.getByText('RISK DECISION')).toBeInTheDocument()
    expect(screen.getByText('REJECTED')).toBeInTheDocument()
  })

  it('disables the protected lifecycle action without all permissions', async () => {
    allowPermissions = false
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (path) => {
      if (path === '/api/v1/broker-accounts') return json({ current_page: 1, last_page: 1, per_page: 15, total: 1, data: [{ public_id: 'account', name: 'Account' }] })
      return json({ current_page: 1, last_page: 1, per_page: 15, total: 1, data: [{ public_id: 'instrument', symbol: 'EURUSD', display_name: 'Euro', is_enabled: true }] })
    })
    render(<PersistentManualTrading />)
    expect(await screen.findByRole('button', { name: 'SIMULATE BUY' })).toBeDisabled()
    expect(screen.getByText('Create, evaluate, and execute permissions are all required.')).toBeInTheDocument()
  })
})

describe('Lifecycle rendering', () => {
  it('visually distinguishes intent, risk, and execution records', () => {
    const intent = { public_id: 'SIM-INT-1', status: 'RISK_APPROVED' } as TradeIntent
    const command = { public_id: 'SIM-CMD-1', status: 'COMPLETED' } as ExecutionCommand
    render(<LifecycleDistinctions intent={intent} decision={{ status: 'APPROVED' } as never} command={command} />)
    expect(screen.getByText('TRADE INTENT: RISK_APPROVED')).toBeInTheDocument()
    expect(screen.getByText('RISK DECISION: APPROVED')).toBeInTheDocument()
    expect(screen.getByText('EXECUTION COMMAND: COMPLETED')).toBeInTheDocument()
  })
})
