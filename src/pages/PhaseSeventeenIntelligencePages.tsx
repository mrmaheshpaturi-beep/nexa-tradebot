import { useCallback, useState } from 'react'
import { MessageSquare, RefreshCw, ShieldAlert, Sparkles } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseSeventeenApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

function synthCandles(n = 120, start = 1.1): Array<Record<string, number | string>> {
  const out: Array<Record<string, number | string>> = []
  let px = start
  for (let i = 0; i < n; i++) {
    const drift = ((i % 17) - 8) * 0.00008
    const open = px
    const close = px + drift
    const t0 = Date.parse('2025-01-01T00:00:00Z') + i * 300_000
    out.push({
      open, high: Math.max(open, close) + 0.00025, low: Math.min(open, close) - 0.00025, close,
      open_time: new Date(t0).toISOString(),
      close_time: new Date(t0 + 299_000).toISOString(),
    })
    px = close
  }
  return out
}

type Tab = 'desk' | 'opportunity' | 'mtf' | 'evidence' | 'memory' | 'chat' | 'detail'

/** Phase 17 Advanced Intelligence desk — extends Phase 13; ADVISORY labeled; read-only tools. */
export function PhaseSeventeenAdvancedDesk() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('desk')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [mode, setMode] = useState<'ADVISORY' | 'SHADOW'>('ADVISORY')
  const [selectedId, setSelectedId] = useState('')
  const [mtfView, setMtfView] = useState<Record<string, unknown> | null>(null)
  const [chatInput, setChatInput] = useState('Explain MTF matrix and suitability in advisory terms.')
  const [chatReply, setChatReply] = useState('')

  const health = useService(useCallback(() => phaseSeventeenApi.health(), []))
  const desk = useService(useCallback(() => phaseSeventeenApi.desk(), []))
  const snapshots = useService(useCallback(
    () => (tab === 'opportunity' || tab === 'desk' ? phaseSeventeenApi.snapshots() : Promise.resolve(null)),
    [tab],
  ))
  const evidence = useService(useCallback(
    () => (tab === 'evidence' ? phaseSeventeenApi.evidence() : Promise.resolve(null)),
    [tab],
  ))
  const memory = useService(useCallback(
    () => (tab === 'memory' ? phaseSeventeenApi.memory() : Promise.resolve(null)),
    [tab],
  ))
  const detail = useService(useCallback(
    () => (tab === 'detail' && selectedId ? phaseSeventeenApi.showSnapshot(selectedId) : Promise.resolve(null)),
    [tab, selectedId],
  ))

  const reload = () => {
    health.reload(); desk.reload()
    if (tab === 'opportunity' || tab === 'desk') snapshots.reload()
    if (tab === 'evidence') evidence.reload()
    if (tab === 'memory') memory.reload()
    if (tab === 'detail') detail.reload()
  }

  const runAssess = async () => {
    if (!can('intelligence.analyze')) return
    setBusy('assess'); setErr(''); setMsg('')
    try {
      const candles = synthCandles()
      const row = await phaseSeventeenApi.assess({
        symbol: 'EURUSD',
        timeframe: 'M5',
        mode,
        candles,
        htf_candles: synthCandles(40, 1.1),
        mtf_candles: { M15: synthCandles(60, 1.1), H1: synthCandles(50, 1.1) },
        cross_market: {
          EURUSD: candles,
          GBPUSD: synthCandles(120, 1.25),
          USDJPY: synthCandles(120, 150),
        },
        include_ai: true,
        record_research: true,
        feature_as_of_epoch: Math.floor(Date.now() / 1000),
        feature_observed_epoch: Math.floor(Date.now() / 1000),
      })
      setSelectedId(String(row.public_id))
      setMsg(`Advanced snapshot ${String(row.public_id)} · ${String(row.mode)} · ${String(row.status)}`)
      setTab('detail')
      reload()
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const runMtf = async () => {
    if (!can('intelligence.analyze')) return
    setBusy('mtf'); setErr(''); setMsg('')
    try {
      const out = await phaseSeventeenApi.mtf({
        candles: synthCandles(),
        htf_candles: synthCandles(40, 1.1),
        mtf_candles: { M15: synthCandles(60, 1.1), H1: synthCandles(50, 1.1) },
      })
      setMtfView(out)
      setTab('mtf')
      setMsg('MTF matrix refreshed (ADVISORY)')
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const runChat = async () => {
    if (!can('intelligence.analyze')) return
    setBusy('chat'); setErr(''); setMsg('')
    try {
      const out = await phaseSeventeenApi.chat({
        messages: [{ role: 'user', content: chatInput }],
      })
      const message = out.message as Record<string, unknown> | undefined
      setChatReply(String(message?.content ?? out.disclaimer ?? '—'))
      setMsg('ADVISORY chat reply (read-only tools)')
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const refuseMutate = async () => {
    setBusy('mutate'); setErr(''); setMsg('')
    try {
      await phaseSeventeenApi.refuseMutate('enable_live')
      setMsg('Unexpected: mutate should 403')
    } catch {
      setMsg('Mutation refused with 403 — advanced intelligence has no execution path')
    } finally {
      setBusy('')
    }
  }

  const h = health.data as Record<string, unknown> | null
  const d = desk.data as Record<string, unknown> | null
  const snapRows = Array.isArray(snapshots.data) ? snapshots.data as Array<Record<string, unknown>> : []
  const ev = evidence.data as Record<string, unknown> | null
  const mem = memory.data as Record<string, unknown> | null
  const det = detail.data as Record<string, unknown> | null
  const payload = (det?.payload ?? {}) as Record<string, unknown>
  const mtfMatrix = (mtfView?.mtf_matrix ?? payload.mtf_matrix ?? {}) as Record<string, unknown>
  const cells = Array.isArray(mtfMatrix.cells) ? mtfMatrix.cells as Array<Record<string, unknown>> : []

  return (
    <div className="page-stack">
      <PageHeader
        title="Advanced Intelligence"
        description="Phase 17 extends Phase 13 TradeIntelligence — ADVISORY / SHADOW only. LIVE HARD BLOCKED."
        actions={(
          <div className="row-actions">
            <StatusBadge tone="warning">ADVISORY</StatusBadge>
            <StatusBadge tone="neutral">SHADOW OK</StatusBadge>
            <StatusBadge tone="bad">LIVE HARD BLOCKED</StatusBadge>
            <button type="button" onClick={reload} aria-label="Refresh advanced intelligence"><RefreshCw size={14} /></button>
          </div>
        )}
      />

      {(health.error || desk.error || err) && <ErrorState message={health.error || desk.error || err} />}
      {msg && <Panel title="Status"><p>{msg}</p></Panel>}

      <div className="metric-grid">
        <MetricCard label="Orchestrator" value={value(h?.orchestrator ?? 'AdvancedIntelligence/v1')} />
        <MetricCard label="Extends" value={value((h?.phase13 as Record<string, unknown> | undefined)?.engine ?? 'TradeIntelligence/v1')} />
        <MetricCard label="Mode" value={mode} />
        <MetricCard label="order_send" value="NONE" />
      </div>

      <Panel title="Controls">
        <div className="row-actions">
          <label>
            Mode
            <select value={mode} onChange={(e) => setMode(e.target.value as 'ADVISORY' | 'SHADOW')} aria-label="Intelligence mode">
              <option value="ADVISORY">ADVISORY</option>
              <option value="SHADOW">SHADOW</option>
            </select>
          </label>
          {can('intelligence.analyze') && (
            <>
              <button type="button" disabled={!!busy} onClick={() => void runAssess()}>
                <Sparkles size={14} /> {busy === 'assess' ? 'Assessing…' : 'Run advanced assess'}
              </button>
              <button type="button" disabled={!!busy} onClick={() => void runMtf()}>MTF matrix</button>
            </>
          )}
          <button type="button" disabled={!!busy} onClick={() => void refuseMutate()}>
            <ShieldAlert size={14} /> Probe mutate (expect 403)
          </button>
        </div>
      </Panel>

      <div className="tab-row" role="tablist">
        {(['desk', 'opportunity', 'mtf', 'evidence', 'memory', 'chat', 'detail'] as Tab[]).map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'active' : ''} onClick={() => setTab(t)}>
            {t}
          </button>
        ))}
      </div>

      {tab === 'desk' && (
        <Panel title="Advanced desk">
          {desk.loading ? <LoadingState /> : (
            <>
              <p>{value(d?.disclaimer)}</p>
              <p>Recent snapshots: {snapRows.length}</p>
              <pre className="code-block">{JSON.stringify({ safety: h, label: d?.label }, null, 2)}</pre>
            </>
          )}
        </Panel>
      )}

      {tab === 'opportunity' && (
        <Panel title="Opportunity board (advanced snapshots)">
          {snapshots.loading ? <LoadingState /> : snapRows.length === 0 ? <EmptyState title="No snapshots" detail="Run advanced assess to populate." /> : (
            <DataTable
              columns={['public_id', 'symbol', 'mode', 'status', 'confidence']}
              rows={snapRows.map((r) => [
                <button key={String(r.public_id)} type="button" onClick={() => { setSelectedId(String(r.public_id)); setTab('detail') }}>{value(r.public_id)}</button>,
                value(r.symbol),
                value(r.mode),
                value(r.status),
                value(r.confidence),
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'mtf' && (
        <Panel title="MTF matrix">
          {cells.length === 0 ? <EmptyState title="Run MTF matrix or advanced assess" detail="No MTF cells yet." /> : (
            <DataTable
              columns={['timeframe', 'bias', 'regime', 'alignment', 'status']}
              rows={cells.map((c) => [
                value(c.timeframe),
                value(c.bias),
                value(c.regime),
                value(c.alignment),
                value(c.status),
              ])}
            />
          )}
          <p>Agreement: {value(mtfMatrix.agreement)}</p>
        </Panel>
      )}

      {tab === 'evidence' && (
        <Panel title="Evidence quality / scoring separation">
          {evidence.loading ? <LoadingState /> : (
            <pre className="code-block">{JSON.stringify(ev, null, 2)}</pre>
          )}
        </Panel>
      )}

      {tab === 'memory' && (
        <Panel title="Immutable research memory">
          {memory.loading ? <LoadingState /> : (
            <>
              <p>{value(mem?.disclaimer)} · immutable={value(mem?.immutable)}</p>
              <pre className="code-block">{JSON.stringify(mem?.records ?? [], null, 2)}</pre>
            </>
          )}
        </Panel>
      )}

      {tab === 'chat' && (
        <Panel title="ADVISORY chat (read-only tools)">
          <textarea aria-label="Chat input" value={chatInput} onChange={(e) => setChatInput(e.target.value)} rows={3} />
          {can('intelligence.analyze') && (
            <button type="button" disabled={!!busy} onClick={() => void runChat()}>
              <MessageSquare size={14} /> {busy === 'chat' ? 'Sending…' : 'Ask (ADVISORY)'}
            </button>
          )}
          {chatReply && <pre className="code-block">{chatReply}</pre>}
        </Panel>
      )}

      {tab === 'detail' && (
        <Panel title="Snapshot detail">
          {!selectedId ? <EmptyState title="Select a snapshot" detail="Choose a public_id from the opportunity board." /> : detail.loading ? <LoadingState /> : (
            <pre className="code-block">{JSON.stringify({
              public_id: det?.public_id,
              mode: det?.mode,
              status: det?.status,
              suitability: det?.suitability,
              uncertainty: det?.uncertainty,
              features: det?.features,
              analogs: det?.analogs,
              mtf_matrix: det?.mtf_matrix,
              scoring_separation: det?.scoring_separation,
              safety: (payload.safety ?? {}),
            }, null, 2)}</pre>
          )}
        </Panel>
      )}
    </div>
  )
}
