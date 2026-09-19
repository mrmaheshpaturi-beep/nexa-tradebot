import { useCallback, useState } from 'react'
import { MessageSquare, RefreshCw, ShieldAlert, Sparkles } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseThirteenApi } from '../api/services'
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

type Tab = 'desk' | 'pulse' | 'board' | 'detail' | 'heatmap' | 'calendar' | 'news' | 'calibration' | 'usage' | 'research' | 'chat'

export function PhaseThirteenIntelligenceDesk() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('desk')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [mode, setMode] = useState<'ADVISORY' | 'SHADOW'>('ADVISORY')
  const [selectedId, setSelectedId] = useState('')
  const [chatInput, setChatInput] = useState('Explain the EURUSD advisory stance.')
  const [chatReply, setChatReply] = useState('')

  const health = useService(useCallback(() => phaseThirteenApi.health(), []))
  const desk = useService(useCallback(() => phaseThirteenApi.desk(), []))
  const pulse = useService(useCallback(() => phaseThirteenApi.pulse(), []))
  const opportunities = useService(useCallback(() => phaseThirteenApi.opportunities(), []))
  const heatmap = useService(useCallback(() => phaseThirteenApi.heatmap(), []))
  const calendar = useService(useCallback(() => phaseThirteenApi.calendar(), []))
  const news = useService(useCallback(() => phaseThirteenApi.news(), []))
  const calibration = useService(useCallback(() => phaseThirteenApi.calibration('DEMO'), []))
  const usage = useService(useCallback(() => phaseThirteenApi.usage(), []))
  const research = useService(useCallback(() => phaseThirteenApi.research(), []))
  const detail = useService(useCallback(
    () => (selectedId ? phaseThirteenApi.showAssessment(selectedId) : Promise.resolve(null)),
    [selectedId],
  ))

  const reload = () => {
    health.reload(); desk.reload(); pulse.reload(); opportunities.reload()
    heatmap.reload(); calendar.reload(); news.reload(); calibration.reload()
    usage.reload(); research.reload(); detail.reload()
  }

  const runAssess = async () => {
    if (!can('intelligence.analyze')) return
    setBusy('assess'); setErr(''); setMsg('')
    try {
      const row = await phaseThirteenApi.assess({
        symbol: 'EURUSD',
        timeframe: 'M5',
        mode,
        candles: synthCandles(),
        htf_candles: synthCandles(40, 1.1),
        include_ai: true,
      })
      setSelectedId(String(row.public_id))
      setMsg(`Assessment ${String(row.public_id)} · ${String(row.mode)} · status ${String(row.status)}`)
      setTab('detail')
      reload()
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const runBoard = async () => {
    if (!can('intelligence.analyze')) return
    setBusy('board'); setErr(''); setMsg('')
    try {
      const candles = synthCandles()
      const board = await phaseThirteenApi.board({
        mode,
        symbols: ['EURUSD', 'GBPUSD', 'USDJPY'],
        candles_by_symbol: { EURUSD: candles, GBPUSD: synthCandles(120, 1.25), USDJPY: synthCandles(120, 150) },
      })
      setMsg(`Board built · ${(board.opportunities as unknown[] | undefined)?.length ?? 0} opportunities · ${mode}`)
      setTab('board')
      reload()
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
      const res = await phaseThirteenApi.chat({
        messages: [{ role: 'user', content: chatInput }],
      })
      setChatReply(String((res.message as Record<string, unknown>)?.content ?? ''))
      setMsg('Read-only advisory chat completed')
      setTab('chat')
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const refuseMutate = async () => {
    setBusy('mutate'); setErr(''); setMsg('')
    try {
      await phaseThirteenApi.refuseMutate('order_send')
      setMsg('Mutation refused (expected)')
    } catch {
      setMsg('Mutation refused with 403 — intelligence has no execution path')
    } finally {
      setBusy('')
    }
  }

  if (health.loading && !health.data) return <LoadingState />
  if (health.error && !health.data) return <ErrorState message={health.error} />

  const tabs: Array<[Tab, string]> = [
    ['desk', 'AI Trade Desk'], ['pulse', 'Market Pulse'], ['board', 'Opportunity Board'],
    ['detail', 'Detail'], ['heatmap', 'Heatmap'], ['calendar', 'Calendar'],
    ['news', 'News'], ['calibration', 'Calibration'], ['usage', 'Usage'],
    ['research', 'Research'], ['chat', 'Explanation Chat'],
  ]

  return (
    <>
      <PageHeader
        title="AI Trade Desk"
        description="Phase 13 Trade Intelligence — ADVISORY / SHADOW only. Never executes trades. LIVE hard-blocked."
        actions={(
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
            <StatusBadge tone="warning">ADVISORY</StatusBadge>
            <StatusBadge tone="neutral">SHADOW OK</StatusBadge>
            <StatusBadge tone="bad">LIVE HARD BLOCKED</StatusBadge>
            <select aria-label="Intelligence mode" value={mode} onChange={(e) => setMode(e.target.value as 'ADVISORY' | 'SHADOW')}>
              <option value="ADVISORY">ADVISORY</option>
              <option value="SHADOW">SHADOW</option>
            </select>
            <button type="button" onClick={reload} aria-label="Refresh intelligence"><RefreshCw size={14} /></button>
          </div>
        )}
      />

      <div className="safety-strip" style={{ marginBottom: 12 }}>
        <ShieldAlert size={14} /> Intelligence cannot call MT5, order_send, position management, risk/settings/strategy mutation.
        CI uses MOCK AI / news / calendar only.
      </div>

      {msg && <p className="placeholder-note">{msg}</p>}
      {err && <ErrorState message={err} />}

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        {tabs.map(([id, label]) => (
          <button key={id} type="button" className={tab === id ? 'active' : ''} onClick={() => setTab(id)}>{label}</button>
        ))}
      </div>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
        {can('intelligence.analyze') && (
          <>
            <button type="button" disabled={!!busy} onClick={runAssess}>
              <Sparkles size={14} /> {busy === 'assess' ? 'Assessing…' : `Assess EURUSD (${mode})`}
            </button>
            <button type="button" disabled={!!busy} onClick={runBoard}>
              {busy === 'board' ? 'Building…' : 'Build Opportunity Board'}
            </button>
            <button type="button" disabled={!!busy} onClick={runChat}>
              <MessageSquare size={14} /> {busy === 'chat' ? 'Chatting…' : 'Ask (read-only)'}
            </button>
          </>
        )}
        <button type="button" disabled={!!busy} onClick={refuseMutate}>Probe mutation refuse</button>
      </div>

      {tab === 'desk' && (
        <div className="metric-grid">
          <MetricCard label="Engine" value={value(health.data?.engine)} />
          <MetricCard label="AI provider" value={value(health.data?.ai_provider)} />
          <MetricCard label="Mode" value={mode} />
          <MetricCard label="order_send" value={String(health.data?.order_send ?? false)} />
          <MetricCard label="LIVE" value={value(health.data?.live_status)} />
          <MetricCard label="Recent assessments" value={String((desk.data?.recent_assessments as unknown[] | undefined)?.length ?? 0)} />
          <Panel title="Desk overview" subtitle="ADVISORY label — not live trading">
            <p>{value(desk.data?.disclaimer)}</p>
            <DataTable
              columns={['Symbol', 'Mode', 'Status', 'Confidence', 'Public ID']}
              rows={((desk.data?.recent_assessments as Array<Record<string, unknown>> | undefined) ?? []).map((a) => [
                value(a.symbol),
                value(a.mode),
                value(a.status),
                value(a.confidence),
                <button key={String(a.public_id)} type="button" onClick={() => { setSelectedId(String(a.public_id)); setTab('detail') }}>{value(a.public_id)}</button>,
              ])}
            />
            {!((desk.data?.recent_assessments as unknown[] | undefined)?.length) && <EmptyState title="No assessments yet" detail="Run an advisory assessment to populate the desk." />}
          </Panel>
        </div>
      )}

      {tab === 'pulse' && (
        <Panel title="Market Pulse" subtitle="ADVISORY">
          <DataTable
            columns={['Symbol', 'Bias', 'Regime', 'Quality', 'Rank', 'Assessment']}
            rows={((pulse.data?.items as Array<Record<string, unknown>> | undefined) ?? []).map((p) => [
              value(p.symbol), value(p.technical_bias), value(p.regime), value(p.quality), value(p.rank_score), value(p.assessment_public_id),
            ])}
          />
        </Panel>
      )}

      {tab === 'board' && (
        <Panel title="Opportunity Board" subtitle={`${mode} — transparent ranking`}>
          <DataTable
            columns={['Rank', 'Symbol', 'Direction', 'Score', 'Mode', 'Disclaimer']}
            rows={((opportunities.data as Array<Record<string, unknown>> | undefined) ?? []).map((o) => [
              value(o.rank_position), value(o.symbol), value(o.direction), value(o.rank_score), value(o.mode), value(o.disclaimer),
            ])}
          />
        </Panel>
      )}

      {tab === 'detail' && (
        <Panel title="Assessment Detail" subtitle={selectedId || 'Select an assessment'}>
          {!selectedId && <EmptyState title="No selection" detail="Assess a symbol or pick from the desk." />}
          {selectedId && detail.data && (
            <>
              <div className="metric-grid">
                <MetricCard label="Symbol" value={value(detail.data.symbol)} />
                <MetricCard label="Mode" value={value(detail.data.mode)} />
                <MetricCard label="Confidence" value={value(detail.data.confidence)} />
                <MetricCard label="order_send" value={String(detail.data.order_send)} />
              </div>
              <pre style={{ whiteSpace: 'pre-wrap', fontSize: 12, maxHeight: 420, overflow: 'auto' }}>
                {JSON.stringify({
                  technical: detail.data.technical,
                  mtf: detail.data.mtf,
                  regime: detail.data.regime,
                  ensemble: detail.data.ensemble,
                  opportunity: detail.data.opportunity,
                  rules_fired: detail.data.rules_fired,
                  safety: (detail.data.payload as Record<string, unknown> | undefined)?.safety,
                }, null, 2)}
              </pre>
            </>
          )}
        </Panel>
      )}

      {tab === 'heatmap' && (
        <Panel title="Opportunity Heatmap" subtitle="ADVISORY scores">
          <DataTable
            columns={['Symbol', 'Direction', 'Score', 'Mode']}
            rows={((heatmap.data?.cells as Array<Record<string, unknown>> | undefined) ?? []).map((c) => [
              value(c.symbol), value(c.direction), value(c.score), value(c.mode),
            ])}
          />
        </Panel>
      )}

      {tab === 'calendar' && (
        <Panel title="Economic Calendar" subtitle={value(calendar.data?.disclaimer)}>
          <StatusBadge tone={(calendar.data?.is_fabricated ? 'warning' : 'neutral') as 'warning' | 'neutral'}>
            {calendar.data?.is_fabricated ? 'FABRICATED MOCK' : value(calendar.data?.provider_status)}
          </StatusBadge>
          <DataTable
            columns={['Time', 'Currency', 'Impact', 'Title', 'Fabricated']}
            rows={((calendar.data?.events as Array<Record<string, unknown>> | undefined) ?? []).map((e) => [
              value(e.event_at), value(e.currency), value(e.impact), value(e.title), String(e.is_fabricated ?? calendar.data?.is_fabricated),
            ])}
          />
        </Panel>
      )}

      {tab === 'news' && (
        <Panel title="News Feed" subtitle={value(news.data?.disclaimer)}>
          <StatusBadge tone={(news.data?.is_fabricated ? 'warning' : 'neutral') as 'warning' | 'neutral'}>
            {news.data?.is_fabricated ? 'FABRICATED MOCK' : value(news.data?.provider_status)}
          </StatusBadge>
          <DataTable
            columns={['Published', 'Source', 'Headline', 'Fabricated']}
            rows={((news.data?.items as Array<Record<string, unknown>> | undefined) ?? []).map((n) => [
              value(n.published_at), value(n.source_label), value(n.headline), String(n.is_fabricated ?? news.data?.is_fabricated),
            ])}
          />
        </Panel>
      )}

      {tab === 'calibration' && (
        <Panel title="Confidence Calibration" subtitle="Sample guards · DEMO evidence only in this view">
          <div className="metric-grid">
            <MetricCard label="Status" value={value(calibration.data?.status)} />
            <MetricCard label="Samples" value={value(calibration.data?.sample_size)} />
            <MetricCard label="Min required" value={value(calibration.data?.min_required)} />
            <MetricCard label="Calibrated" value={value(calibration.data?.calibrated_confidence)} />
            <MetricCard label="Guard" value={value(calibration.data?.guard)} />
          </div>
        </Panel>
      )}

      {tab === 'usage' && (
        <Panel title="Usage & Queue" subtitle="Budgets and failure isolation">
          <DataTable
            columns={['Meter', 'Period', 'Used', 'Budget']}
            rows={((usage.data?.meters as Array<Record<string, unknown>> | undefined) ?? []).map((m) => [
              value(m.meter_key), value(m.period_yyyymm), value(m.used), value(m.budget),
            ])}
          />
          <pre style={{ fontSize: 12 }}>{JSON.stringify(usage.data?.queue ?? {}, null, 2)}</pre>
        </Panel>
      )}

      {tab === 'research' && (
        <Panel title="Research View" subtitle="Evidence labels kept strictly distinct">
          <p>{value(research.data?.disclaimer)}</p>
          <p>Labels: {((research.data?.evidence_labels as string[] | undefined) ?? []).join(' · ')}</p>
          <DataTable
            columns={['Symbol', 'Mode', 'Status', 'Hash']}
            rows={((research.data?.assessments as Array<Record<string, unknown>> | undefined) ?? []).map((a) => [
              value(a.symbol), value(a.mode), value(a.status), value(a.content_hash),
            ])}
          />
        </Panel>
      )}

      {tab === 'chat' && (
        <Panel title="Read-only Explanation Chat" subtitle="No mutation tools">
          <textarea aria-label="Chat question" value={chatInput} onChange={(e) => setChatInput(e.target.value)} rows={3} style={{ width: '100%' }} />
          <p style={{ marginTop: 12 }}><strong>Reply:</strong> {chatReply || '—'}</p>
        </Panel>
      )}
    </>
  )
}

export function PhaseThirteenNewsCalendar() {
  return <PhaseThirteenIntelligenceDesk />
}
