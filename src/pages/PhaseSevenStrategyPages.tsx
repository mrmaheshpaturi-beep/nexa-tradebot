import { useCallback, useEffect, useMemo, useState } from 'react'
import { Check, Radar, ShieldAlert } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { makePhaseThreeIdentity, phaseSevenApi, phaseThreeApi, phaseTwoApi } from '../api/services'
import type { Signal, StrategyRecord } from '../api/types'
import { ConfirmationDialog, DataTable, DirectionBadge, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge } from '../components/ui'
import { useAuth } from '../auth/authState'
import { useService } from '../hooks/useService'

const n = (value: string | number | null | undefined) => Number(value ?? 0)
const value = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? '—' : String(v))

function signalEligible(signal: Signal) {
  return signal.environment === 'SIMULATION' && ['BUY', 'SELL'].includes(signal.direction)
    && ['GENERATED', 'VALID'].includes(signal.status) && !signal.intent
    && (!signal.expires_at || new Date(signal.expires_at) > new Date())
}

export function PhaseSevenSignals() {
  const { can } = useAuth()
  const result = useService(useCallback(() => Promise.all([
    phaseThreeApi.signals(),
    phaseThreeApi.accounts(),
    phaseSevenApi.health(),
  ]), []))
  const [selectedAccount, setAccountId] = useState('')
  const [working, setWorking] = useState<string | null>(null)
  const [created, setCreated] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [detailId, setDetailId] = useState<string | null>(null)
  const identities = useMemo(() => ({ current: {} as Record<string, string> }), [])

  useEffect(() => {
    const first = result.data?.[1]?.data?.[0]?.public_id
    if (!selectedAccount && first) setAccountId(first)
  }, [result.data, selectedAccount])

  const detail = useService(useCallback(
    () => (detailId ? phaseThreeApi.signal(detailId) : Promise.resolve(null as unknown as Signal)),
    [detailId],
  ))

  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Signals unavailable.'} />
  const [signals, accountPage, health] = result.data

  const simulate = async (signal: Signal) => {
    if (working || created[signal.public_id]) return
    const key = identities.current[signal.public_id] ??= makePhaseThreeIdentity('signal-intent')
    setWorking(signal.public_id); setErrors((old) => ({ ...old, [signal.public_id]: '' }))
    try {
      const intent = await phaseThreeApi.createSignalIntent(signal.public_id, {
        account_public_id: selectedAccount,
        requested_volume: n(signal.instrument?.minimum_volume || .01), risk_percent: 1,
        idempotency_key: key,
      })
      setCreated((old) => ({ ...old, [signal.public_id]: intent.public_id }))
    } catch (error) {
      setErrors((old) => ({ ...old, [signal.public_id]: firstValidationError(error) }))
    } finally {
      setWorking(null)
    }
  }

  return <>
    <PageHeader
      title="Signals"
      description="Strategy-engine signals with transparent confluence scores. Scores are not win probabilities. SIMULATE creates simulation intents only."
      actions={<StatusBadge tone="info">PHASE 7 · NO AI CLAIMS</StatusBadge>}
    />
    <div className="metrics-grid">
      <MetricCard label="Signals" value={signals.total} detail="Persisted strategy/mock signals" />
      <MetricCard label="Engine plugins" value={String((health as { plugins?: number }).plugins ?? 12)} detail="Built-in only" />
      <MetricCard label="Auto trading" value="DISABLED" detail="Broker execution blocked" />
      <MetricCard label="order_send" value="NONE" detail="Read-only MT5 posture" />
    </div>
    <label className="field signal-account" htmlFor="signal-account">
      <span>Simulation account</span>
      <select id="signal-account" value={selectedAccount} onChange={(e) => setAccountId(e.target.value)}>
        {accountPage.data.map((a) => <option value={a.public_id} key={a.public_id}>{a.name}</option>)}
      </select>
    </label>
    {!signals.data.length ? <EmptyState title="No signals yet" detail="Enable a strategy and run evaluation, or use the scanner." /> :
      <div className="signal-grid">{signals.data.map((signal) => <article className="signal-card" key={signal.public_id}>
        <div className="signal-card-head">
          <div>
            <span className="sim-label">{signal.source} · SIMULATION</span>
            <h2>{signal.symbol} <DirectionBadge value={signal.direction} /></h2>
            <p>{signal.strategy?.name ?? signal.plugin_key ?? 'Unassigned'} · {signal.timeframe ?? '—'} · {signal.market_regime ?? '—'}</p>
          </div>
          <strong>{n(signal.confluence_score ?? signal.score).toFixed(0)}/100</strong>
        </div>
        <div className="price-levels">
          <div><span>Entry</span><strong>{value(signal.entry_reference ?? signal.entry_price)}</strong></div>
          <div><span>Stop loss</span><strong>{value(signal.stop_loss)}</strong></div>
          <div><span>Take profit 1</span><strong>{value(signal.take_profit_1_reference ?? signal.take_profit_1)}</strong></div>
          <div><span>Status</span><strong>{signal.status}</strong></div>
        </div>
        <div className="mock-explanation">
          <strong>Explainability</strong>
          <p>{signal.explanation ?? 'No explanation stored.'}</p>
          <p className="muted">Score is confluence (0–100), not a guarantee.</p>
        </div>
        {errors[signal.public_id] && <p className="form-alert error" role="alert">{errors[signal.public_id]}</p>}
        {created[signal.public_id] && <p className="form-alert success"><Check /> Intent {created[signal.public_id]} created. Not broker-executed.</p>}
        <div className="row-actions">
          <button className="btn" type="button" onClick={() => setDetailId(signal.public_id)}>Details</button>
          <button className="btn primary" type="button" disabled={!can('simulation_lifecycle.create') || !selectedAccount || !signalEligible(signal) || !!working || !!created[signal.public_id]} onClick={() => simulate(signal)}>
            {working === signal.public_id ? 'CREATING…' : 'SIMULATE'}
          </button>
        </div>
      </article>)}</div>}

    {detailId && (
      <ConfirmationDialog
        open
        title={`Signal ${detailId}`}
        onConfirm={() => setDetailId(null)}
        onCancel={() => setDetailId(null)}
      >
        {detail.loading && <LoadingState />}
        {detail.error && <ErrorState message={detail.error} />}
        {detail.data && detailId && <div className="settings-list">
          <div><span>Plugin</span><strong>{detail.data.plugin_key ?? '—'}</strong></div>
          <div><span>Score</span><strong>{value(detail.data.score)}</strong></div>
          <div><span>Confluence</span><strong>{value(detail.data.confluence_score)}</strong></div>
          <div><span>Fingerprint</span><strong>{detail.data.fingerprint ?? '—'}</strong></div>
          <div><span>Candle close</span><strong>{detail.data.candle_close_key ?? '—'}</strong></div>
          <pre className="code-block">{JSON.stringify(detail.data.score_breakdown ?? detail.data.confluence ?? {}, null, 2)}</pre>
        </div>}
      </ConfirmationDialog>
    )}
  </>
}

export function PhaseSevenStrategies() {
  const { can } = useAuth()
  const load = useCallback(() => Promise.all([
    phaseTwoApi.strategies(),
    phaseSevenApi.catalog(),
  ]).then(([strategies, catalog]) => ({ strategies, catalog })), [])
  const { data, loading, error, reload } = useService(load)
  const [busy, setBusy] = useState<number | null>(null)
  const [message, setMessage] = useState('')
  const [scanSymbol, setScanSymbol] = useState('EURUSD')
  const [scan, setScan] = useState<Awaited<ReturnType<typeof phaseSevenApi.scan>> | null>(null)
  const [matrix, setMatrix] = useState<Array<Record<string, unknown>> | null>(null)

  if (loading) return <LoadingState />
  if (error || !data) return <ErrorState message={error ?? 'Strategies unavailable.'} />

  const evaluate = async (strategy: StrategyRecord) => {
    setBusy(strategy.id); setMessage('')
    try {
      const result = await phaseSevenApi.evaluate(strategy.id, { prefer: 'simulation', create_signal: true })
      const evalStatus = (result.evaluation as { status?: string } | undefined)?.status ?? 'done'
      const skipped = (result.signal_result as { skipped?: string } | undefined)?.skipped
      setMessage(`Evaluated ${strategy.name}: ${evalStatus} · signal ${result.signal ? 'created/replayed' : skipped ?? 'none'}`)
      reload()
    } catch (err) {
      setMessage(firstValidationError(err))
    } finally {
      setBusy(null)
    }
  }

  const toggle = async (strategy: StrategyRecord) => {
    setBusy(strategy.id)
    try {
      if (strategy.enabled) await phaseSevenApi.disable(strategy.id)
      else await phaseSevenApi.enable(strategy.id)
      reload()
    } catch (err) {
      setMessage(firstValidationError(err))
    } finally {
      setBusy(null)
    }
  }

  const runScan = async () => {
    setMessage('')
    try {
      setScan(await phaseSevenApi.scan(scanSymbol, 'M5', 'simulation'))
    } catch (err) {
      setMessage(firstValidationError(err))
    }
  }

  const runMatrix = async () => {
    try {
      const res = await phaseSevenApi.matrix('simulation')
      setMatrix(res.matrix)
    } catch (err) {
      setMessage(firstValidationError(err))
    }
  }

  return <>
    <PageHeader
      title="Strategies"
      description="Built-in Phase 7 strategy plugins. Signal generation only — auto trading stays disabled."
      actions={<StatusBadge tone="warning">AUTO TRADING DISABLED</StatusBadge>}
    />
    {message && <p className="form-alert info" role="status">{message}</p>}
    <Panel title="Strategy library" subtitle={`${data.strategies.total} persisted · ${data.catalog.plugins.length} plugins`}>
      <DataTable
        columns={['Strategy', 'Plugin', 'Category', 'Symbols', 'TFs', 'Status', 'Min score', 'Version', 'Actions']}
        rows={data.strategies.data.map((strategy) => [
          <strong key={strategy.id}>{strategy.name}</strong>,
          strategy.plugin_key ?? '—',
          strategy.category,
          strategy.symbols.join(', '),
          strategy.timeframes.join(', '),
          <StatusBadge key={`${strategy.id}-status`} tone={strategy.enabled ? 'good' : 'neutral'}>{strategy.status}</StatusBadge>,
          n(strategy.minimum_signal_score) <= 1 ? (n(strategy.minimum_signal_score) * 100).toFixed(0) : n(strategy.minimum_signal_score).toFixed(0),
          strategy.version,
          <div className="row-actions" key={`${strategy.id}-actions`}>
            {can('strategies.update') && <button type="button" disabled={busy === strategy.id} onClick={() => toggle(strategy)}>{strategy.enabled ? 'Disable' : 'Enable'}</button>}
            {can('strategies.view') && <button type="button" disabled={busy === strategy.id} onClick={() => evaluate(strategy)}>Evaluate</button>}
          </div>,
        ])}
      />
    </Panel>

    <div className="two-thirds-grid">
      <Panel title="Plugin catalog" subtitle="Arbitrary code upload is not allowed">
        <DataTable
          columns={['Key', 'Name', 'Category', 'Evidence family']}
          rows={data.catalog.plugins.map((p) => [p.key, p.name, p.category, p.evidence_family])}
        />
      </Panel>
      <Panel title="Scanner & confluence" subtitle="Analysis only">
        <div className="field">
          <span>Symbol</span>
          <select value={scanSymbol} onChange={(e) => setScanSymbol(e.target.value)}>
            {['EURUSD', 'XAUUSD', 'GBPUSD', 'USDJPY'].map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div className="row-actions">
          <button className="btn primary" type="button" onClick={runScan}><Radar size={16} /> Scan</button>
          <button className="btn" type="button" onClick={runMatrix}>Strategy matrix</button>
        </div>
        {scan && <div className="settings-list">
          <div><span>Direction</span><strong>{scan.confluence.direction}</strong></div>
          <div><span>Confluence</span><strong>{scan.confluence.score}/100</strong></div>
          <div><span>Supporting</span><strong>{(scan.confluence.supporting_plugins ?? []).join(', ') || '—'}</strong></div>
          <p className="muted">{scan.disclaimer}</p>
        </div>}
        {matrix && <DataTable
          columns={['Symbol', 'TF', 'Direction', 'Score', 'Plugins']}
          rows={matrix.map((row) => [
            String(row.symbol), String(row.timeframe), String(row.direction), String(row.confluence_score),
            Array.isArray(row.signal_plugins) ? row.signal_plugins.join(', ') : '—',
          ])}
        />}
      </Panel>
    </div>
    <p className="muted"><ShieldAlert size={14} /> No “guaranteed win” language. Scores explain confluence only.</p>
  </>
}

/** Back-compat export */
export function PersistentSignals() {
  return <PhaseSevenSignals />
}
