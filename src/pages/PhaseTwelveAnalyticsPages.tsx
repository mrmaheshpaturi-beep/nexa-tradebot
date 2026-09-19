import { useCallback, useMemo, useState } from 'react'
import { Download, Play, RefreshCw, ShieldAlert } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseTwelveApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

function synthCandles(n = 180, start = 1.1): Array<Record<string, number | string>> {
  const out: Array<Record<string, number | string>> = []
  let px = start
  for (let i = 0; i < n; i++) {
    const drift = ((i % 17) - 8) * 0.00008
    const open = px
    const close = px + drift
    const high = Math.max(open, close) + 0.00025
    const low = Math.min(open, close) - 0.00025
    const t0 = Date.parse('2025-01-01T00:00:00Z') + i * 300_000
    out.push({
      open, high, low, close,
      open_time: new Date(t0).toISOString(),
      close_time: new Date(t0 + 299_000).toISOString(),
    })
    px = close
  }
  return out
}

export function PhaseTwelveAnalyticsDashboard() {
  const { can } = useAuth()
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [tab, setTab] = useState<'performance' | 'backtest' | 'research' | 'compare' | 'explorer'>('performance')

  const health = useService(useCallback(() => phaseTwelveApi.health(), []))
  const dashboard = useService(useCallback(() => phaseTwelveApi.dashboard(), []))
  const datasets = useService(useCallback(() => phaseTwelveApi.datasets(), []))
  const snapshots = useService(useCallback(() => phaseTwelveApi.snapshots(), []))
  const runs = useService(useCallback(() => phaseTwelveApi.runs(), []))
  const trades = useService(useCallback(() => phaseTwelveApi.trades(), []))
  const comparisons = useService(useCallback(() => phaseTwelveApi.comparisons(), []))
  const queue = useService(useCallback(() => phaseTwelveApi.queueStats(), []))

  const reload = () => {
    health.reload(); dashboard.reload(); datasets.reload(); snapshots.reload()
    runs.reload(); trades.reload(); comparisons.reload(); queue.reload()
  }

  const metrics = dashboard.data?.metrics as Record<string, Record<string, unknown>> | undefined
  const core = metrics?.core ?? {}
  const riskAdj = metrics?.risk_adjusted ?? {}
  const rMult = metrics?.r_multiple ?? {}

  const equity = useMemo(() => {
    const curve = (metrics?.equity_curve as number[] | undefined) ?? []
    return curve.map((v, i) => ({ name: String(i + 1), value: v }))
  }, [metrics])

  const buildDataset = async () => {
    if (!can('analytics.manage')) return
    setBusy('dataset'); setErr(''); setMsg('')
    try {
      const ds = await phaseTwelveApi.buildDataset({
        name: `DEMO dataset ${new Date().toISOString()}`,
        source_environment: 'DEMO',
        filters: {},
      })
      const snap = await phaseTwelveApi.createSnapshot({ dataset_id: String(ds.public_id), label: 'auto' })
      setMsg(`Dataset ${String(ds.public_id)} · snapshot ${String(snap.public_id)}`)
      reload()
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const runBacktest = async (kind: string) => {
    if (!can('backtest.run')) return
    setBusy(`bt-${kind}`); setErr(''); setMsg('')
    try {
      const candles = synthCandles(200)
      const htf = synthCandles(50, 1.1)
      const snap = await phaseTwelveApi.createDataSnapshot({
        symbol: 'EURUSD',
        timeframe: 'M5',
        candles,
        mtf_candles: htf,
        source: 'INLINE_UI',
      })
      const run = await phaseTwelveApi.queueRun({
        data_snapshot_id: String(snap.public_id),
        strategy_key: 'ema_trend',
        run_kind: kind,
        seed: 42,
        process_now: true,
        parameters: { warmup: 60, start_equity: 10000, require_mtf: false },
        cost_model: { spread_points: 1, commission_per_lot: 7, slippage_points: 0.5 },
        risk_profile: { max_risk_per_trade: 1, sl_atr_mult: 1.5, tp_atr_mult: 2.5 },
        management_policy: {
          break_even_enabled: true,
          break_even_trigger_r: 1,
          trailing_enabled: true,
          trailing_start_r: 1.5,
        },
      })
      setMsg(`BACKTEST run ${String(run.public_id)} · status ${String(run.status)} · kind ${kind}`)
      setTab('backtest')
      reload()
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const compare = async () => {
    if (!can('analytics.manage')) return
    const run = runs.data?.[0]
    const snap = snapshots.data?.[0]
    if (!run || !snap) {
      setErr('Need at least one BACKTEST run and one DEMO analytics snapshot.')
      return
    }
    setBusy('compare'); setErr(''); setMsg('')
    try {
      const row = await phaseTwelveApi.compare({
        backtest_run_id: String(run.public_id),
        demo_snapshot_id: String(snap.public_id),
      })
      setMsg(`Comparison ${String(row.public_id)} — labels BACKTEST vs DEMO kept distinct`)
      setTab('compare')
      reload()
    } catch (error) {
      setErr(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  if (dashboard.loading && !dashboard.data && health.loading) return <LoadingState />
  if (dashboard.error && health.error) return <ErrorState message={dashboard.error} />

  const tabs = [
    ['performance', 'Performance'],
    ['backtest', 'Backtest console'],
    ['research', 'Research'],
    ['compare', 'Comparison'],
    ['explorer', 'Trade explorer'],
  ] as const

  return (
    <div className="page-stack">
      <PageHeader
        title="Analytics & Research"
        description="Phase 12 — DEMO/SIMULATION analytics and BACKTEST research. LIVE hard-blocked. No auto-promote."
        actions={(
          <div className="action-row">
            <StatusBadge tone="info">PHASE 12</StatusBadge>
            <StatusBadge tone="warning">LIVE BLOCKED</StatusBadge>
            <StatusBadge tone="good">BACKTEST ≠ DEMO</StatusBadge>
          </div>
        )}
      />

      {(msg || err) && (
        <Panel title={err ? 'Error' : 'Status'}>
          <p className={err ? 'negative' : 'positive'}>{err || msg}</p>
        </Panel>
      )}

      <div className="metrics-grid">
        <MetricCard label="Analytics engine" value={value((health.data?.analytics as Record<string, unknown> | undefined)?.status)} />
        <MetricCard label="Backtest engine" value={value((health.data?.backtest as Record<string, unknown> | undefined)?.status)} />
        <MetricCard label="Queue (bounded)" value={value(queue.data?.queued)} />
        <MetricCard label="Broker writes" value="0" />
      </div>

      <div className="action-row" style={{ gap: 8, flexWrap: 'wrap' }}>
        {tabs.map(([id, label]) => (
          <button key={id} type="button" className={tab === id ? 'primary' : 'secondary'} onClick={() => setTab(id)}>
            {label}
          </button>
        ))}
        <button type="button" className="secondary" onClick={reload} disabled={!!busy}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {tab === 'performance' && (
        <>
          <div className="action-row">
            {can('analytics.manage') && (
              <button type="button" className="primary" disabled={busy === 'dataset'} onClick={buildDataset}>
                Build DEMO dataset + snapshot
              </button>
            )}
          </div>
          <div className="metrics-grid">
            <MetricCard label="Completed trades" value={value(core.completed_trades)} />
            <MetricCard label="Win rate" value={core.win_rate == null ? 'N/A' : `${(Number(core.win_rate) * 100).toFixed(1)}%`} />
            <MetricCard label="Net P/L" value={value(core.net_pnl)} />
            <MetricCard label="Profit factor" value={value(core.profit_factor)} />
            <MetricCard label="Sharpe" value={value(riskAdj.sharpe)} />
            <MetricCard label="Max DD" value={value(riskAdj.max_drawdown)} />
            <MetricCard label="Avg R" value={value(rMult.avg_r)} />
            <MetricCard label="Win-rate status" value={value(core.win_rate_status)} />
          </div>
          <Panel title="Equity curve" subtitle="From latest analytics snapshot (DEMO/SIMULATION outcomes)">
            {equity.length === 0
              ? <EmptyState title="No equity series" detail="Build a dataset from finalized TradeSummaries." />
              : (
                <DataTable
                  columns={['Bar', 'Equity']}
                  rows={equity.slice(-40).map((p) => [p.name, String(p.value)])}
                />
              )}
          </Panel>
          <Panel title="Snapshots">
            <DataTable
              columns={['ID', 'Label', 'Hash', 'Captured']}
              rows={(snapshots.data ?? []).map((s) => [
                value(s.public_id), value(s.label), value(String(s.content_hash).slice(0, 12)), value(s.captured_at),
              ])}
            />
          </Panel>
        </>
      )}

      {tab === 'backtest' && (
        <>
          <Panel title="Backtest console" subtitle="Environment label is always BACKTEST. Closed-candle, no lookahead, cost model, Phase 9/11 sim reuse.">
            <div className="action-row" style={{ flexWrap: 'wrap', gap: 8 }}>
              {(['SINGLE', 'WALK_FORWARD', 'OPTIMIZATION', 'MONTE_CARLO', 'PORTFOLIO'] as const).map((kind) => (
                <button
                  key={kind}
                  type="button"
                  className="primary"
                  disabled={!can('backtest.run') || !!busy}
                  onClick={() => runBacktest(kind)}
                >
                  <Play size={14} /> {kind}
                </button>
              ))}
            </div>
            <p className="muted" style={{ marginTop: 12 }}>
              Intrabar policy default: OHLC_PATH (BUY O-L-H-C / SELL O-H-L-C). Monte Carlo uses seeded shuffle (seed + path index).
            </p>
          </Panel>
          <Panel title="Runs">
            <DataTable
              columns={['ID', 'Kind', 'Status', 'Strategy', 'Env', 'Lineage']}
              rows={(runs.data ?? []).map((r) => [
                value(r.public_id),
                value(r.run_kind),
                value(r.status),
                value(r.strategy_key),
                value(r.environment),
                value(String(r.lineage_hash ?? '').slice(0, 12)),
              ])}
            />
          </Panel>
        </>
      )}

      {tab === 'research' && (
        <Panel title="Strategy evaluation / research" subtitle="Reports never auto-promote StrategySetting or RiskProfile.">
          <div className="action-row">
            <ShieldAlert size={16} />
            <span>Promote endpoints return 403 by design.</span>
          </div>
          <DataTable
            columns={['Dataset', 'Env', 'Rows', 'Hash']}
            rows={(datasets.data ?? []).map((d) => [
              value(d.public_id), value(d.source_environment), value(d.row_count), value(String(d.content_hash).slice(0, 12)),
            ])}
          />
        </Panel>
      )}

      {tab === 'compare' && (
        <>
          <div className="action-row">
            {can('analytics.manage') && (
              <button type="button" className="primary" disabled={!!busy} onClick={compare}>
                Compare latest BACKTEST vs DEMO snapshot
              </button>
            )}
          </div>
          <Panel title="Backtest vs DEMO" subtitle="Strict separation — labels never mixed or silently substituted.">
            <DataTable
              columns={['ID', 'BACKTEST label', 'DEMO label', 'Created']}
              rows={(comparisons.data ?? []).map((c) => [
                value(c.public_id), value(c.backtest_label), value(c.demo_label), value(c.created_at),
              ])}
            />
          </Panel>
        </>
      )}

      {tab === 'explorer' && (
        <Panel title="Trade explorer" subtitle="Analytics dataset rows from TradeSummary lineage.">
          {(trades.data ?? []).length === 0 ? (
            <EmptyState title="No trades" detail="Finalize DEMO managed positions or build a dataset." />
          ) : (
            <DataTable
              columns={['Symbol', 'Dir', 'PnL', 'R', 'MAE', 'MFE', 'Session', 'TF']}
              rows={(trades.data ?? []).slice(0, 80).map((t) => [
                value(t.symbol), value(t.direction), value(t.realized_pnl), value(t.r_multiple),
                value(t.mae), value(t.mfe), value(t.session), value(t.timeframe),
              ])}
            />
          )}
          {can('analytics.export') && snapshots.data?.[0] && (
            <a
              className="secondary"
              style={{ display: 'inline-flex', gap: 6, marginTop: 12 }}
              href={`/api/v1/analytics/snapshots/${encodeURIComponent(String(snapshots.data[0].public_id))}/export?format=csv`}
            >
              <Download size={14} /> Export latest snapshot CSV
            </a>
          )}
        </Panel>
      )}
    </div>
  )
}

export function PhaseTwelveBacktestConsole() {
  return <PhaseTwelveAnalyticsDashboard />
}
