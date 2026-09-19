import { useCallback, useEffect, useState } from 'react'
import { Network, RefreshCw, ShieldAlert, Wallet } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseEighteenApi, phaseTwoApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

type Tab = 'command' | 'connections' | 'wizard' | 'exposure' | 'risk' | 'allocation' | 'routing' | 'reconciliation'

export function PhaseEighteenFleetPages() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('command')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [providerName, setProviderName] = useState('Nexa MT5 Demo')
  const [providerCode, setProviderCode] = useState('MT5_DEMO')
  const [connName, setConnName] = useState('Mock Terminal A')
  const [portfolioName, setPortfolioName] = useState('Core DEMO Fleet')
  const [selectedAccount, setSelectedAccount] = useState('')
  const [selectedPortfolio, setSelectedPortfolio] = useState('')
  const [selectedProvider, setSelectedProvider] = useState('')
  const [selectedConnection, setSelectedConnection] = useState('')
  const [brokerAccounts, setBrokerAccounts] = useState<Array<Record<string, unknown>>>([])
  const [selectedBrokerAccount, setSelectedBrokerAccount] = useState('')
  const [wizardLogin, setWizardLogin] = useState('900001')
  const [wizardServer, setWizardServer] = useState('Nexa-Demo')

  const dash = useService(useCallback(() => phaseEighteenApi.dashboard(), []))
  const health = useService(useCallback(() => phaseEighteenApi.health(), []))

  useEffect(() => {
    const id = window.setInterval(() => { dash.reload(); health.reload() }, 30000)
    return () => window.clearInterval(id)
  }, [dash, health])

  useEffect(() => {
    if (!can('broker_accounts.view')) return
    let cancelled = false
    phaseTwoApi.brokerAccounts().then((rows) => {
      if (cancelled) return
      const list = (rows.data ?? []).map((b) => b as unknown as Record<string, unknown>)
      setBrokerAccounts(list)
      setSelectedBrokerAccount((prev) => prev || (list[0] ? String(list[0].public_id ?? '') : ''))
    }).catch(() => undefined)
    return () => { cancelled = true }
  }, [can])

  const reload = () => { dash.reload(); health.reload() }

  const accounts = (dash.data?.accounts as Array<Record<string, unknown>> | undefined) ?? []
  const portfolios = (dash.data?.portfolios as Array<Record<string, unknown>> | undefined) ?? []
  const connections = (dash.data?.connections as Array<Record<string, unknown>> | undefined) ?? []
  const providers = (dash.data?.providers as Array<Record<string, unknown>> | undefined) ?? []
  const locks = (dash.data?.risk_locks as Array<Record<string, unknown>> | undefined) ?? []
  const routes = (dash.data?.routes as Array<Record<string, unknown>> | undefined) ?? []
  const recon = (dash.data?.reconciliation as Array<Record<string, unknown>> | undefined) ?? []
  const allocations = (dash.data?.allocations as Array<Record<string, unknown>> | undefined) ?? []

  const effectiveProvider = selectedProvider || (providers[0] ? String(providers[0].public_id) : '')
  const effectiveConnection = selectedConnection || (connections[0] ? String(connections[0].public_id) : '')
  const effectiveAccount = selectedAccount || (accounts[0] ? String(accounts[0].public_id) : '')
  const effectivePortfolio = selectedPortfolio || (portfolios[0] ? String(portfolios[0].public_id) : '')

  const registerProvider = async () => {
    if (!can('fleet.manage')) return
    setBusy('provider'); setErr(''); setMsg('')
    try {
      const row = await phaseEighteenApi.registerProvider({ code: providerCode, name: providerName, platform: 'MT5' })
      setSelectedProvider(String(row.public_id))
      setMsg(`Provider ${value(row.code)} registered`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Provider failed') }
    finally { setBusy('') }
  }

  const registerConnection = async () => {
    if (!can('fleet.manage') || !effectiveProvider) return
    setBusy('conn'); setErr(''); setMsg('')
    try {
      const row = await phaseEighteenApi.registerConnection({
        provider_public_id: effectiveProvider,
        name: connName,
        endpoint_mode: 'MOCK',
      })
      setSelectedConnection(String(row.public_id))
      setMsg(`Connection ${value(row.name)} registered (MOCK terminal)`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Connection failed') }
    finally { setBusy('') }
  }

  const wizardRegister = async () => {
    if (!can('fleet.manage') || !effectiveProvider || !selectedBrokerAccount) return
    setBusy('wizard'); setErr(''); setMsg('')
    try {
      const row = await phaseEighteenApi.registerAccount({
        provider_public_id: effectiveProvider,
        broker_account_public_id: selectedBrokerAccount,
        connection_public_id: effectiveConnection || undefined,
        display_name: `Fleet ${wizardLogin}`,
        login: wizardLogin,
        server: wizardServer,
        environment: 'DEMO',
        currency: 'USD',
      })
      setSelectedAccount(String(row.public_id))
      setMsg(`Fleet account ${value(row.public_id)} registered — verify environment before trading`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Account wizard failed') }
    finally { setBusy('') }
  }

  const verifySelected = async () => {
    if (!effectiveAccount || !can('fleet.operate')) return
    setBusy('verify'); setErr(''); setMsg('')
    try {
      await phaseEighteenApi.verifyAccount(effectiveAccount)
      setMsg('Independent DEMO environment verification OK')
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Verification failed / SAFE_MODE') }
    finally { setBusy('') }
  }

  const createPortfolio = async () => {
    if (!can('fleet.manage')) return
    setBusy('portfolio'); setErr(''); setMsg('')
    try {
      const row = await phaseEighteenApi.createPortfolio({ name: portfolioName, base_currency: 'USD' })
      setSelectedPortfolio(String(row.public_id))
      setMsg(`Portfolio ${value(row.name)} created`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Portfolio failed') }
    finally { setBusy('') }
  }

  const addMember = async () => {
    if (!effectivePortfolio || !effectiveAccount || !can('fleet.manage')) return
    setBusy('member'); setErr(''); setMsg('')
    try {
      await phaseEighteenApi.addMembership(effectivePortfolio, { fleet_account_public_id: effectiveAccount, role: 'MEMBER' })
      setMsg('Membership added')
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Membership failed') }
    finally { setBusy('') }
  }

  const activateAlloc = async () => {
    if (!effectivePortfolio || !effectiveAccount || !can('fleet.manage')) return
    setBusy('alloc'); setErr(''); setMsg('')
    try {
      const plan = await phaseEighteenApi.activateAllocation(effectivePortfolio, { [effectiveAccount]: 1 })
      setMsg(`Allocation v${value(plan.version)} hash ${value(plan.plan_hash).slice(0, 12)}…`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Allocation failed') }
    finally { setBusy('') }
  }

  const captureHealth = async () => {
    setBusy('health'); setErr(''); setMsg('')
    try {
      const snap = await phaseEighteenApi.captureHealth()
      setMsg(`Fleet health ${value(snap.overall)}`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Health capture failed') }
    finally { setBusy('') }
  }

  const emergencyHalt = async () => {
    if (!can('fleet.emergency')) return
    setBusy('halt'); setErr(''); setMsg('')
    try {
      await phaseEighteenApi.emergency({ scope: 'FLEET', action: 'HALT', reason: 'Operator halt from command center' })
      setMsg('Fleet HALT — SAFE_MODE on all accounts')
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Emergency failed') }
    finally { setBusy('') }
  }

  const reconcile = async () => {
    if (!effectiveAccount || !can('fleet.reconcile')) return
    setBusy('recon'); setErr(''); setMsg('')
    try {
      const run = await phaseEighteenApi.reconcile(effectiveAccount, {
        restart_recovery: true,
        observed_positions: [{ login: wizardLogin, owned_by_nexa: true }],
      })
      setMsg(`Recon ${value(run.status)} foreign=${value(run.foreign_positions)}`)
      reload()
    } catch (e) { setErr(firstValidationError(e) || 'Reconciliation failed') }
    finally { setBusy('') }
  }

  const refuseAi = async () => {
    setBusy('ai'); setErr(''); setMsg('')
    try {
      await phaseEighteenApi.refuseAiRoute()
      setMsg('Unexpected: AI route should 403')
    } catch {
      setMsg('AI route/allocate/risk refused (403) — correct')
    } finally { setBusy('') }
  }

  const h = health.data ?? {}
  const fleetHealth = dash.data?.health as Record<string, unknown> | undefined

  return (
    <div className="page-stack phase-eighteen-fleet">
      <PageHeader
        title="Portfolio Command Center"
        description="Multi-account DEMO fleet — Phase 10 sole execution · no copy trading · LIVE/UNKNOWN hard-blocked"
        actions={(
          <button type="button" className="btn ghost" disabled={!!busy} onClick={reload}>
            <RefreshCw size={16} /> Refresh
          </button>
        )}
      />

      <div className="metric-grid">
        <MetricCard label="Fleet" value="Phase 18" detail={value(h.fleet_version)} />
        <MetricCard label="Execution" value="Phase 10" detail="No new order_send" />
        <MetricCard label="Copy trading" value="ABSENT" detail="Hard rejected" />
        <MetricCard label="LIVE_AUTO" value="NONE" detail="Does not exist" />
        <MetricCard label="AI route/alloc/risk" value="NONE" detail="403 probes" />
        <MetricCard label="Windows MT5" value="PENDING" detail="Mock terminals in CI" />
      </div>

      <div className="tab-row" role="tablist">
        {([
          ['command', 'Command Center'],
          ['connections', 'Connections'],
          ['wizard', 'Account Wizard'],
          ['exposure', 'Exposure'],
          ['risk', 'Global Risk'],
          ['allocation', 'Allocation'],
          ['routing', 'Routing'],
          ['reconciliation', 'Reconciliation'],
        ] as const).map(([id, label]) => (
          <button key={id} type="button" role="tab" aria-selected={tab === id} className={tab === id ? 'tab active' : 'tab'} onClick={() => setTab(id)}>
            {label}
          </button>
        ))}
      </div>

      {(err || msg) && (
        <Panel title={err ? 'Error' : 'Status'}>
          <p className={err ? 'error-text' : 'muted'}>{err || msg}</p>
        </Panel>
      )}

      {dash.loading && !dash.data ? <LoadingState /> : null}
      {dash.error ? <ErrorState message={String(dash.error)} /> : null}

      {tab === 'command' && (
        <Panel title="Fleet command" subtitle="Health, emergencies, and account isolation">
          <div className="action-row">
            <button type="button" className="btn" disabled={!!busy} onClick={captureHealth}><Network size={16} /> Capture fleet health</button>
            <button type="button" className="btn danger" disabled={!!busy || !can('fleet.emergency')} onClick={emergencyHalt}><ShieldAlert size={16} /> Fleet HALT</button>
            <button type="button" className="btn ghost" disabled={!!busy} onClick={refuseAi}>Probe AI route (403)</button>
          </div>
          <div className="metric-grid" style={{ marginTop: 16 }}>
            <MetricCard label="Accounts" value={value(accounts.length)} />
            <MetricCard label="Portfolios" value={value(portfolios.length)} />
            <MetricCard label="Overall" value={value(fleetHealth?.overall ?? '—')} />
            <MetricCard label="Safe mode" value={value((fleetHealth?.components as Record<string, unknown> | undefined)?.safe_mode_accounts ?? 0)} />
          </div>
          {accounts.length === 0 ? <EmptyState title="No fleet accounts" detail="Use the Account Wizard to register a DEMO account." /> : (
            <DataTable
              columns={['Account', 'Env', 'Status', 'Safe mode', 'Login']}
              rows={accounts.map((a) => [
                value(a.display_name),
                value(a.environment),
                <StatusBadge key={String(a.public_id)} tone="info">{String(a.status)}</StatusBadge>,
                value(a.safe_mode ? 'YES' : 'NO'),
                value(a.login),
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'connections' && (
        <Panel title="Broker connections" subtitle="Secret refs only — worker isolation — MOCK by default">
          <div className="form-grid">
            <label>Provider code<input value={providerCode} onChange={(e) => setProviderCode(e.target.value)} /></label>
            <label>Provider name<input value={providerName} onChange={(e) => setProviderName(e.target.value)} /></label>
            <label>Connection name<input value={connName} onChange={(e) => setConnName(e.target.value)} /></label>
          </div>
          <div className="action-row">
            <button type="button" className="btn" disabled={!!busy || !can('fleet.manage')} onClick={registerProvider}>Register provider</button>
            <button type="button" className="btn" disabled={!!busy || !can('fleet.manage') || !effectiveProvider} onClick={registerConnection}>Register MOCK connection</button>
          </div>
          <DataTable
            columns={['Provider', 'Platform', 'Status']}
            rows={providers.map((p) => [value(p.code), value(p.platform), value(p.status)])}
          />
          <DataTable
            columns={['Connection', 'Mode', 'Health']}
            rows={connections.map((c) => [value(c.name), value(c.endpoint_mode), value(c.health)])}
          />
        </Panel>
      )}

      {tab === 'wizard' && (
        <Panel title="Account wizard" subtitle="Independent environment verification required before broker-changing readiness">
          <div className="form-grid">
            <label>Broker account
              <select value={selectedBrokerAccount} onChange={(e) => setSelectedBrokerAccount(e.target.value)}>
                <option value="">Select…</option>
                {brokerAccounts.map((b) => (
                  <option key={String(b.public_id)} value={String(b.public_id)}>{value(b.name)} ({value(b.environment)})</option>
                ))}
              </select>
            </label>
            <label>Login<input value={wizardLogin} onChange={(e) => setWizardLogin(e.target.value)} /></label>
            <label>Server<input value={wizardServer} onChange={(e) => setWizardServer(e.target.value)} /></label>
          </div>
          <div className="action-row">
            <button type="button" className="btn" disabled={!!busy || !can('fleet.manage')} onClick={wizardRegister}><Wallet size={16} /> Register fleet account</button>
            <button type="button" className="btn" disabled={!!busy || !can('fleet.operate') || !effectiveAccount} onClick={verifySelected}>Verify DEMO environment</button>
          </div>
          <p className="muted">LIVE / UNKNOWN are hard-blocked. Fingerprint mismatch enters SAFE_MODE.</p>
        </Panel>
      )}

      {tab === 'exposure' && (
        <Panel title="Exposure & valuation" subtitle="Currency-normalized portfolio analytics">
          <div className="action-row">
            <button type="button" className="btn" disabled={!!busy || !can('fleet.manage')} onClick={createPortfolio}>Create portfolio</button>
            <button type="button" className="btn" disabled={!!busy || !can('fleet.manage')} onClick={addMember}>Add selected account</button>
            <button
              type="button"
              className="btn ghost"
              disabled={!effectivePortfolio || !!busy}
              onClick={async () => {
                setBusy('analytics'); setErr(''); setMsg('')
                try {
                  const a = await phaseEighteenApi.portfolioAnalytics(effectivePortfolio)
                  setMsg(`Base equity ${value(a.total_equity_base)} ${value(a.base_currency)}`)
                } catch (e) { setErr(firstValidationError(e) || 'Analytics failed') }
                finally { setBusy('') }
              }}
            >
              Load analytics
            </button>
          </div>
          <label>Portfolio name<input value={portfolioName} onChange={(e) => setPortfolioName(e.target.value)} /></label>
          <DataTable columns={['Portfolio', 'Base FX', 'Status']} rows={portfolios.map((p) => [value(p.name), value(p.base_currency), value(p.status)])} />
        </Panel>
      )}

      {tab === 'risk' && (
        <Panel title="Account / portfolio / global risk locks" subtitle="Extends Phase 9 — AI cannot create locks">
          <div className="action-row">
            <button
              type="button"
              className="btn"
              disabled={!!busy || !can('fleet.risk')}
              onClick={async () => {
                setBusy('lock'); setErr(''); setMsg('')
                try {
                  await phaseEighteenApi.createRiskLock({
                    scope: 'GLOBAL',
                    lock_code: 'FLEET_GLOBAL_HALT',
                    reason: 'Operator global lock from UI',
                  })
                  setMsg('Global risk lock ACTIVE')
                  reload()
                } catch (e) { setErr(firstValidationError(e) || 'Lock failed') }
                finally { setBusy('') }
              }}
            >
              Create GLOBAL lock
            </button>
          </div>
          {locks.length === 0 ? <EmptyState title="No active fleet risk locks" detail="Create ACCOUNT, PORTFOLIO, or GLOBAL locks from this panel." /> : (
            <DataTable columns={['Scope', 'Code', 'Reason', 'Status']} rows={locks.map((l) => [value(l.scope), value(l.lock_code), value(l.reason), value(l.status)])} />
          )}
        </Panel>
      )}

      {tab === 'allocation' && (
        <Panel title="Versioned allocation" subtitle="Deterministic weights — AI cannot author">
          <div className="action-row">
            <button type="button" className="btn" disabled={!!busy || !can('fleet.manage')} onClick={activateAlloc}>Activate 100% → selected account</button>
          </div>
          <DataTable
            columns={['Version', 'Hash', 'Status']}
            rows={allocations.map((a) => [value(a.version), value(a.plan_hash).slice(0, 16), value(a.status)])}
          />
        </Panel>
      )}

      {tab === 'routing' && (
        <Panel title="Account-aware routing" subtitle="Routes into Phase 10 only — account-bound idempotency — no copy trading">
          <p className="muted">Submit DEMO intents via Phase 10 ExecutionEngine after fleet route decision ROUTED_PHASE10.</p>
          <DataTable
            columns={['Decision', 'Idempotency', 'Copy?']}
            rows={routes.map((r) => [value(r.route_decision), value(r.idempotency_key), value(r.copy_trading ? 'YES' : 'NO')])}
          />
        </Panel>
      )}

      {tab === 'reconciliation' && (
        <Panel title="Per-account reconciliation" subtitle="Foreign-position safety + restart recovery">
          <div className="action-row">
            <button type="button" className="btn" disabled={!!busy || !can('fleet.reconcile') || !effectiveAccount} onClick={reconcile}>Reconcile selected account</button>
          </div>
          <DataTable
            columns={['Status', 'Foreign', 'Matched', 'Safe mode']}
            rows={recon.map((r) => [value(r.status), value(r.foreign_positions), value(r.matched_positions), value(r.safe_mode_triggered ? 'YES' : 'NO')])}
          />
        </Panel>
      )}
    </div>
  )
}
