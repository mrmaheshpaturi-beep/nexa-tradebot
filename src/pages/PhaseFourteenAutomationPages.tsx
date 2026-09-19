import { useCallback, useState } from 'react'
import { OctagonX, Pause, Play, RefreshCw, ShieldAlert, Square } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseFourteenApi, phaseTwoApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

type Tab = 'center' | 'preflight' | 'pipeline' | 'workflows' | 'rejections' | 'executions' | 'events'

export function PhaseFourteenAutomationControlCenter() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('center')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [mode, setMode] = useState<'OFF' | 'DRY_RUN' | 'DEMO_AUTO'>('DRY_RUN')
  const [phrase1, setPhrase1] = useState('ENABLE DRY RUN')
  const [phrase2, setPhrase2] = useState('CONFIRM DRY RUN START')
  const [sessionId, setSessionId] = useState('')
  const [profileId, setProfileId] = useState('')
  const [accountId, setAccountId] = useState('')
  const [preflight, setPreflight] = useState<Record<string, unknown> | null>(null)

  const health = useService(useCallback(() => phaseFourteenApi.health(), []))
  const center = useService(useCallback(() => phaseFourteenApi.controlCenter(), []))
  const workflows = useService(useCallback(() => (tab === 'workflows' ? phaseFourteenApi.workflows() : Promise.resolve(null)), [tab]))
  const rejections = useService(useCallback(() => (tab === 'rejections' ? phaseFourteenApi.rejections() : Promise.resolve(null)), [tab]))
  const executions = useService(useCallback(() => (tab === 'executions' ? phaseFourteenApi.executions() : Promise.resolve(null)), [tab]))
  const events = useService(useCallback(() => (tab === 'events' ? phaseFourteenApi.events() : Promise.resolve(null)), [tab]))
  const accounts = useService(useCallback(() => phaseTwoApi.brokerAccounts(), []))

  const reload = () => {
    health.reload(); center.reload()
    if (tab === 'workflows') workflows.reload()
    if (tab === 'rejections') rejections.reload()
    if (tab === 'executions') executions.reload()
    if (tab === 'events') events.reload()
  }

  const setModePath = (next: 'OFF' | 'DRY_RUN' | 'DEMO_AUTO') => {
    setMode(next)
    if (next === 'DRY_RUN') {
      setPhrase1('ENABLE DRY RUN')
      setPhrase2('CONFIRM DRY RUN START')
    } else if (next === 'DEMO_AUTO') {
      setPhrase1('ENABLE AUTO DEMO TRADING')
      setPhrase2('CONFIRM AUTO DEMO START')
    }
  }

  const runPreflight = async () => {
    setBusy('preflight'); setErr(''); setMsg('')
    try {
      const body: Record<string, unknown> = { mode }
      if (accountId) body.broker_account_public_id = accountId
      const data = await phaseFourteenApi.preflight(body)
      setPreflight(data)
      setMsg(`Pre-flight ${data.ok ? 'PASS' : 'BLOCKED'}`)
      setTab('preflight')
    } catch (e) {
      setErr(firstValidationError(e) || 'Pre-flight failed')
    } finally {
      setBusy('')
    }
  }

  const ensureProfile = async () => {
    if (!can('automation.manage')) return
    setBusy('profile'); setErr(''); setMsg('')
    try {
      const created = await phaseFourteenApi.createProfile({
        name: 'Control Center DEMO Profile',
        symbol_universe: ['EURUSD'],
        timeframe_universe: ['H1'],
        strategy_matrix: [{ symbol: 'EURUSD', timeframe: 'H1', strategy_key: 'ema_trend', strategy_version: 'v1' }],
        intelligence_required: false,
        session_policy: { trade_weekends: true, respect_symbol_hours: true, respect_dst: true },
      })
      const validated = await phaseFourteenApi.validateProfile(String(created.public_id))
      const activated = await phaseFourteenApi.activateProfile(String(validated.public_id))
      setProfileId(String(activated.public_id))
      setMsg(`Profile ACTIVE ${activated.public_id}`)
      center.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Profile setup failed')
    } finally {
      setBusy('')
    }
  }

  const enableAutoDemo = async () => {
    if (!can('automation.manage')) return
    setBusy('auto'); setErr(''); setMsg('')
    try {
      await phaseFourteenApi.enableAutoDemo('ENABLE AUTO DEMO TRADING')
      setMsg('AUTO DEMO TRADING setting enabled (not AUTO LIVE)')
      health.reload(); center.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Enable AUTO DEMO failed — ensure allow_demo_execution is on')
    } finally {
      setBusy('')
    }
  }

  const startTwoStep = async () => {
    if (!can('automation.operate')) return
    setBusy('start'); setErr(''); setMsg('')
    try {
      const step1 = await phaseFourteenApi.startStep1({
        mode,
        confirmation_phrase: phrase1,
        profile_public_id: profileId || undefined,
        broker_account_public_id: accountId || undefined,
      }) as { session?: { public_id?: string }; preflight?: Record<string, unknown> }
      const sid = String(step1.session?.public_id || '')
      setSessionId(sid)
      if (step1.preflight) setPreflight(step1.preflight)
      const running = await phaseFourteenApi.startStep2(sid, phrase2)
      setMsg(`Session RUNNING (${value((running as { mode?: string }).mode || mode)}) — UI label AUTO DEMO never AUTO LIVE`)
      center.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Two-step start failed')
    } finally {
      setBusy('')
    }
  }

  const dryRunTick = async () => {
    if (!sessionId || !can('automation.operate')) return
    setBusy('tick'); setErr(''); setMsg('')
    try {
      const data = await phaseFourteenApi.tick(sessionId, {
        symbol: 'EURUSD',
        timeframe: 'H1',
        direction: 'BUY',
        strategy_key: 'ema_trend',
        strategy_version: 'v1',
        confluence_score: 72,
        candle_state: 'CLOSED',
        signal_at: new Date().toISOString(),
        candle_id: `c-${Date.now()}`,
      })
      const wf = (data as { workflow?: { public_id?: string; state?: string; dry_run?: boolean; broker_touched?: boolean } }).workflow
      setMsg(`Tick → workflow ${value(wf?.public_id)} state=${value(wf?.state)} dry_run=${String(wf?.dry_run)} broker_touched=${String(wf?.broker_touched)}`)
      workflows.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Tick failed')
    } finally {
      setBusy('')
    }
  }

  const sessionAction = async (action: 'pause' | 'resume' | 'stop' | 'kill') => {
    if (!sessionId) return
    setBusy(action); setErr(''); setMsg('')
    try {
      if (action === 'pause') await phaseFourteenApi.pause(sessionId)
      if (action === 'resume') await phaseFourteenApi.resume(sessionId)
      if (action === 'stop') await phaseFourteenApi.stop(sessionId)
      if (action === 'kill') await phaseFourteenApi.killSwitch(sessionId)
      setMsg(`${action.toUpperCase()} applied`)
      center.reload()
    } catch (e) {
      setErr(firstValidationError(e) || `${action} failed`)
    } finally {
      setBusy('')
    }
  }

  const refuseLive = async () => {
    setBusy('live'); setErr(''); setMsg('')
    try {
      await phaseFourteenApi.refuseLiveAuto()
      setMsg('Unexpected: LIVE_AUTO should 403')
    } catch {
      setMsg('LIVE_AUTO refused (403) — correct')
    } finally {
      setBusy('')
    }
  }

  const cc = center.data as Record<string, unknown> | null
  const session = (cc?.session || null) as Record<string, unknown> | null
  const accountRows = (() => {
    const raw = accounts.data as unknown
    if (Array.isArray(raw)) return raw as Array<Record<string, unknown>>
    if (raw && typeof raw === 'object' && Array.isArray((raw as { data?: unknown }).data)) {
      return (raw as { data: Array<Record<string, unknown>> }).data
    }
    return [] as Array<Record<string, unknown>>
  })()
  const pageRows = (payload: unknown): Array<Record<string, unknown>> => {
    if (!payload) return []
    if (Array.isArray(payload)) return payload
    const nested = (payload as { data?: unknown }).data
    if (Array.isArray(nested)) return nested
    if (nested && typeof nested === 'object' && Array.isArray((nested as { data?: unknown }).data)) {
      return (nested as { data: Array<Record<string, unknown>> }).data
    }
    return []
  }

  return (
    <>
      <PageHeader
        title="Auto Trading Control Center"
        description="Phase 14 Automated DEMO Trading Orchestrator — OFF → DRY RUN → AUTO DEMO. AUTO LIVE does not exist."
        actions={(
          <div className="header-actions">
            <StatusBadge tone="neutral">AUTO DEMO</StatusBadge>
            <StatusBadge tone="bad">AUTO LIVE — NOT AVAILABLE</StatusBadge>
            <StatusBadge tone={(cc?.settings as { emergency_stop?: boolean } | undefined)?.emergency_stop ? 'bad' : 'good'}>
              {(cc?.settings as { emergency_stop?: boolean } | undefined)?.emergency_stop ? 'EMERGENCY STOP' : 'STOP CLEARED'}
            </StatusBadge>
            <button type="button" className="ghost-button" onClick={reload}><RefreshCw size={16} /> Refresh</button>
          </div>
        )}
      />

      {(health.error || center.error) && <ErrorState message={String(health.error || center.error)} />}
      {(health.loading || center.loading) && !cc && <LoadingState />}

      <div className="metrics-grid">
        <MetricCard label="Default state" value="OFF" detail="Never auto-starts on boot" />
        <MetricCard label="Mode path" value="OFF → DRY_RUN → DEMO_AUTO" detail="No LIVE_AUTO" />
        <MetricCard label="Session" value={value(session?.state || 'NONE')} detail={value(session?.mode)} />
        <MetricCard label="Phase 14 order_send" value="NONE" detail="Phase 10 sole path" />
      </div>

      {(msg || err) && (
        <Panel title="Operator feedback">
          {msg && <p className="success-inline">{msg}</p>}
          {err && <p className="error-inline">{err}</p>}
        </Panel>
      )}

      <div className="tabs phase14-tabs">
        {(['center', 'preflight', 'pipeline', 'workflows', 'rejections', 'executions', 'events'] as Tab[]).map((t) => (
          <button key={t} type="button" className={tab === t ? 'active' : ''} onClick={() => setTab(t)}>{t}</button>
        ))}
      </div>
      {tab === 'center' && (
        <div className="stack-gap">
          <Panel title="Mode selector (UI path)">
            <div className="button-row">
              {(['OFF', 'DRY_RUN', 'DEMO_AUTO'] as const).map((m) => (
                <button key={m} type="button" className={mode === m ? 'btn primary' : 'btn ghost'} onClick={() => setModePath(m)}>
                  {m === 'DEMO_AUTO' ? 'AUTO DEMO' : m === 'DRY_RUN' ? 'DRY RUN' : 'OFF'}
                </button>
              ))}
              <button type="button" className="btn ghost" disabled title="Not available">AUTO LIVE</button>
            </div>
            <p className="muted">Selected: <strong>{mode === 'DEMO_AUTO' ? 'AUTO DEMO TRADING' : mode}</strong>. LIVE_AUTO hard-rejected.</p>
          </Panel>

          <Panel title="Setup">
            <div className="button-row">
              <button type="button" className="btn primary" disabled={!!busy || !can('automation.manage')} onClick={ensureProfile}>
                Create → Validate → Activate profile
              </button>
              <button type="button" className="btn ghost" disabled={!!busy || !can('automation.manage')} onClick={enableAutoDemo}>
                Enable AUTO DEMO setting
              </button>
              <button type="button" className="btn ghost" disabled={!!busy} onClick={runPreflight}>Run pre-flight</button>
              <button type="button" className="btn ghost" disabled={!!busy} onClick={refuseLive}>Probe LIVE_AUTO (expect 403)</button>
            </div>
            <label className="field">
              <span>DEMO account</span>
              <select value={accountId} onChange={(e) => setAccountId(e.target.value)}>
                <option value="">— select —</option>
                {accountRows.map((a) => (
                  <option key={String(a.public_id)} value={String(a.public_id)}>
                    {String(a.name)} ({String(a.environment)})
                  </option>
                ))}
              </select>
            </label>
            <p className="muted">Profile: {value(profileId)} · Session: {value(sessionId || session?.public_id)}</p>
          </Panel>

          <Panel title="Two-step start">
            <label className="field"><span>Step 1 phrase</span><input value={phrase1} onChange={(e) => setPhrase1(e.target.value)} /></label>
            <label className="field"><span>Step 2 phrase</span><input value={phrase2} onChange={(e) => setPhrase2(e.target.value)} /></label>
            <div className="button-row">
              <button type="button" className="btn primary" disabled={!!busy || mode === 'OFF' || !can('automation.operate')} onClick={startTwoStep}>
                <Play size={16} /> Start {mode === 'DEMO_AUTO' ? 'AUTO DEMO' : 'DRY RUN'}
              </button>
              <button type="button" className="btn ghost" disabled={!!busy || !sessionId} onClick={dryRunTick}>Tick candidate (dry-run safe)</button>
            </div>
          </Panel>

          <Panel title="Kill switch / pause / resume / stop">
            <div className="button-row">
              <button type="button" className="btn ghost" disabled={!sessionId} onClick={() => sessionAction('pause')}><Pause size={16} /> Pause</button>
              <button type="button" className="btn ghost" disabled={!sessionId} onClick={() => sessionAction('resume')}><Play size={16} /> Resume</button>
              <button type="button" className="btn ghost" disabled={!sessionId} onClick={() => sessionAction('stop')}><Square size={16} /> Stop</button>
              <button type="button" className="btn danger" disabled={!sessionId || !can('automation.kill')} onClick={() => sessionAction('kill')}>
                <OctagonX size={16} /> Kill switch
              </button>
            </div>
            <p className="muted"><ShieldAlert size={14} /> Kill switch blocks entries and does not auto close-all. CLOSE-ALL is a separate Phase 11 action.</p>
          </Panel>

          {(cc?.banners as { safe_mode?: boolean; kill_switch?: boolean } | undefined)?.safe_mode && (
            <Panel title="SAFE MODE banner"><p className="error-text">SAFE MODE — entries blocked. Explicit resume required after DEMO re-verify. No LIVE cleanup.</p></Panel>
          )}
        </div>
      )}

      {tab === 'preflight' && (
        <Panel title="Pre-flight checks">
          {!preflight && <EmptyState title="No pre-flight yet" detail="Run pre-flight from Control Center." />}
          {preflight && (
            <>
              <StatusBadge tone={preflight.ok ? 'good' : 'bad'}>{preflight.ok ? 'PASS' : 'BLOCKED'}</StatusBadge>
              <pre className="code-block">{JSON.stringify(preflight, null, 2)}</pre>
            </>
          )}
        </Panel>
      )}

      {tab === 'pipeline' && (
        <Panel title="Pipeline">
          <DataTable
            columns={['Stage', 'Ready']}
            rows={Object.entries((cc?.pipelines as Record<string, boolean>) || {}).map(([k, v]) => [k, v ? 'YES' : 'NO'])}
          />
          <p className="muted">Market Data → Scanner → Signals → Candidates → Intelligence → Qualification → Risk → Execution (P10) → MT5 DEMO → Management (P11) → Analytics (P12)</p>
        </Panel>
      )}

      {tab === 'workflows' && (
        <Panel title="Workflows">
          {workflows.loading && <LoadingState />}
          <DataTable
            columns={['ID', 'Symbol', 'State', 'Dry run', 'Broker']}
            rows={pageRows(workflows.data).map((w) => [value(w.public_id), value(w.symbol), value(w.state), String(w.dry_run), String(w.broker_touched)])}
          />
        </Panel>
      )}

      {tab === 'rejections' && (
        <Panel title="Rejection feed">
          {rejections.loading && <LoadingState />}
          <DataTable
            columns={['ID', 'Code', 'Stage', 'Detail']}
            rows={pageRows(rejections.data).map((w) => [value(w.public_id), value(w.rejection_code), value(w.rejection_stage), value(w.rejection_detail)])}
          />
        </Panel>
      )}

      {tab === 'executions' && (
        <Panel title="Execution feed">
          {executions.loading && <LoadingState />}
          <DataTable
            columns={['ID', 'State', 'Symbol', 'Broker touched']}
            rows={pageRows(executions.data).map((w) => [value(w.public_id), value(w.state), value(w.symbol), String(w.broker_touched)])}
          />
        </Panel>
      )}

      {tab === 'events' && (
        <Panel title="Immutable automation events">
          {events.loading && <LoadingState />}
          <DataTable
            columns={['Type', 'Severity', 'At']}
            rows={pageRows(events.data).map((e) => [value(e.event_type), value(e.severity), value(e.occurred_at)])}
          />
        </Panel>
      )}
    </>
  )
}
