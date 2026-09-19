import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, Bell, Pause, RefreshCw, ShieldAlert, Siren } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseFifteenApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

type Tab =
  | 'operations'
  | 'alerts'
  | 'validation'
  | 'risk'
  | 'execution'
  | 'reconciliation'
  | 'queue'
  | 'server'
  | 'incidents'
  | 'comparisons'
  | 'scorecard'

function isStale(iso: unknown, maxAgeSec = 180): boolean {
  if (!iso || typeof iso !== 'string') return true
  const t = Date.parse(iso)
  if (Number.isNaN(t)) return true
  return (Date.now() - t) / 1000 > maxAgeSec
}

export function PhaseFifteenOperationsCenter() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('operations')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [lastRefresh, setLastRefresh] = useState(() => new Date().toISOString())

  const ops = useService(useCallback(() => phaseFifteenApi.operations(), []))
  const alerts = useService(useCallback(() => (tab === 'alerts' ? phaseFifteenApi.alerts() : Promise.resolve(null)), [tab]))
  const lab = useService(useCallback(() => (tab === 'validation' ? phaseFifteenApi.validationLab() : Promise.resolve(null)), [tab]))
  const scorecard = useService(useCallback(() => (tab === 'scorecard' ? phaseFifteenApi.scorecard() : Promise.resolve(null)), [tab]))
  const incidents = useService(useCallback(() => (tab === 'incidents' ? phaseFifteenApi.incidents() : Promise.resolve(null)), [tab]))
  const comparisons = useService(useCallback(() => (tab === 'comparisons' ? phaseFifteenApi.comparisons() : Promise.resolve(null)), [tab]))
  const risk = useService(useCallback(() => (tab === 'risk' ? phaseFifteenApi.risk() : Promise.resolve(null)), [tab]))
  const execution = useService(useCallback(() => (tab === 'execution' ? phaseFifteenApi.executionQuality() : Promise.resolve(null)), [tab]))
  const reconciliation = useService(useCallback(() => (tab === 'reconciliation' ? phaseFifteenApi.reconciliation() : Promise.resolve(null)), [tab]))
  const queue = useService(useCallback(() => (tab === 'queue' ? phaseFifteenApi.queue() : Promise.resolve(null)), [tab]))
  const server = useService(useCallback(() => (tab === 'server' ? phaseFifteenApi.server() : Promise.resolve(null)), [tab]))
  const tradingReady = useService(useCallback(() => phaseFifteenApi.tradingReadiness(), []))

  useEffect(() => {
    const id = window.setInterval(() => {
      ops.reload()
      tradingReady.reload()
      setLastRefresh(new Date().toISOString())
    }, 30000)
    return () => window.clearInterval(id)
  }, [ops, tradingReady])

  const reload = () => {
    ops.reload(); tradingReady.reload(); setLastRefresh(new Date().toISOString())
    if (tab === 'alerts') alerts.reload()
    if (tab === 'validation') lab.reload()
    if (tab === 'scorecard') scorecard.reload()
    if (tab === 'incidents') incidents.reload()
    if (tab === 'comparisons') comparisons.reload()
    if (tab === 'risk') risk.reload()
    if (tab === 'execution') execution.reload()
    if (tab === 'reconciliation') reconciliation.reload()
    if (tab === 'queue') queue.reload()
    if (tab === 'server') server.reload()
  }

  const runWatchdog = async () => {
    if (!can('observability.operate')) return
    setBusy('watchdog'); setErr(''); setMsg('')
    try {
      const data = await phaseFifteenApi.watchdog()
      setMsg(`Watchdog ${value(data.action)} — duplicates_orders=${value(data.duplicates_orders)}`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Watchdog failed')
    } finally {
      setBusy('')
    }
  }

  const raiseTestAlert = async () => {
    if (!can('observability.operate')) return
    setBusy('alert'); setErr(''); setMsg('')
    try {
      await phaseFifteenApi.raiseAlert({
        category: 'OPS',
        title: 'Operator test alert',
        severity: 'WARNING',
        body: 'Phase 15 Alert Center test — IN_APP only',
      })
      setMsg('Alert raised')
      setTab('alerts')
      alerts.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Raise alert failed')
    } finally {
      setBusy('')
    }
  }

  const startForwardValidation = async () => {
    if (!can('observability.manage')) return
    setBusy('validation'); setErr(''); setMsg('')
    try {
      const session = await phaseFifteenApi.startValidation({ mode: 'DEMO_FORWARD', config: { note: 'Validation Lab' } })
      await phaseFifteenApi.observeValidation(String(session.public_id), {
        stage: 'scanned',
        outcome: 'PASS',
        symbol: 'EURUSD',
      })
      setMsg(`Validation session ${value(session.public_id)} started`)
      setTab('validation')
      lab.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Validation start failed')
    } finally {
      setBusy('')
    }
  }

  const runBackup = async () => {
    if (!can('observability.manage')) return
    setBusy('backup'); setErr(''); setMsg('')
    try {
      const data = await phaseFifteenApi.backup()
      setMsg(`Backup ${value(data.status)} verified=${value(data.verified)} restore_tested=${value(data.restore_tested)}`)
    } catch (e) {
      setErr(firstValidationError(e) || 'Backup failed')
    } finally {
      setBusy('')
    }
  }

  const emergencyPause = async () => {
    if (!can('observability.operate') && !can('emergency_stop.manage')) return
    setBusy('emergency'); setErr(''); setMsg('')
    try {
      await phaseFifteenApi.watchdog()
      setMsg('Emergency controls: watchdog inspected — AUTO ENTRY remains operator-controlled')
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Emergency action failed')
    } finally {
      setBusy('')
    }
  }

  if (ops.loading && !ops.data) return <LoadingState />
  if (ops.error && !ops.data) return <ErrorState message={ops.error} />

  const health = (ops.data?.health ?? {}) as Record<string, unknown>
  const stale = isStale(lastRefresh, 90)

  const tabs: Array<[Tab, string]> = [
    ['operations', 'System Operations'],
    ['alerts', 'Alert Center'],
    ['validation', 'DEMO Validation Lab'],
    ['risk', 'Risk'],
    ['execution', 'Execution Quality'],
    ['reconciliation', 'Reconciliation'],
    ['queue', 'Queue'],
    ['server', 'Server'],
    ['incidents', 'Incidents'],
    ['comparisons', 'Comparisons'],
    ['scorecard', 'Readiness Scorecard'],
  ]

  return (
    <>
      <PageHeader
        title="System Operations"
        description="Phase 15 observability — operational readiness only. LIVE hard-blocked. No automatic safe-for-real-money claim."
        actions={(
          <div className="inline-controls">
            <button className="btn ghost" onClick={reload}><RefreshCw size={16} /> Refresh</button>
            <button className="btn" disabled={!can('observability.operate') || busy === 'watchdog'} onClick={runWatchdog}>Watchdog</button>
            <button className="btn danger mobile-emergency" disabled={busy === 'emergency'} onClick={emergencyPause}>
              <Siren size={16} /> Emergency inspect
            </button>
          </div>
        )}
      />

      <div className="danger-banner" role="status">
        <ShieldAlert /> LIVE HARD BLOCKED · LIVE_AUTO DOES NOT EXIST · Phase 15 order_send = 0
      </div>
      {stale && (
        <div className="info-banner" role="status">
          <AlertTriangle size={14} /> Data may be stale — last refresh {value(lastRefresh)}. Auto-refresh every 30s.
        </div>
      )}
      {msg && <div className="info-banner">{msg}</div>}
      {err && <div className="danger-banner" role="alert">{err}</div>}

      <div className="metric-grid">
        <MetricCard label="Overall health" value={value(health.overall_status)} />
        <MetricCard label="Trading readiness" value={value(tradingReady.data?.trading_readiness ?? health.trading_readiness)} />
        <MetricCard label="New entries blocked" value={value(health.new_entries_blocked)} />
        <MetricCard label="Open alerts" value={value(ops.data?.alerts_open)} />
      </div>

      <div className="tab-row" role="tablist">
        {tabs.map(([id, label]) => (
          <button key={id} className={`tab ${tab === id ? 'active' : ''}`} onClick={() => setTab(id)} type="button">{label}</button>
        ))}
      </div>

      {tab === 'operations' && (
        <Panel title="Dependency graph" subtitle="CRITICAL vs OPTIONAL · stale CRITICAL heartbeat blocks new entries">
          <div className="health-meta">
            {Object.entries((health.dependencies ?? {}) as Record<string, Record<string, unknown>>).map(([name, dep]) => (
              <div key={name}>
                <span>{name} · {value(dep.criticality)}</span>
                <strong>
                  <StatusBadge tone={dep.status === 'HEALTHY' ? 'good' : dep.status === 'DEGRADED' ? 'warning' : 'bad'}>
                    {value(dep.status)}
                  </StatusBadge>
                </strong>
              </div>
            ))}
          </div>
          <div className="inline-controls" style={{ marginTop: 12 }}>
            <button className="btn" disabled={!can('observability.manage') || busy === 'backup'} onClick={runBackup}>Run backup</button>
            <button className="btn ghost" disabled={!can('observability.operate') || busy === 'alert'} onClick={raiseTestAlert}><Bell size={14} /> Test alert</button>
            <button className="btn ghost" disabled={!can('observability.manage') || busy === 'validation'} onClick={startForwardValidation}>Start DEMO forward validation</button>
          </div>
          <pre className="code-block" style={{ marginTop: 12, whiteSpace: 'pre-wrap' }}>
            {JSON.stringify({ banners: ops.data?.banners, env: ops.data?.env, circuits: ops.data?.circuits }, null, 2)}
          </pre>
        </Panel>
      )}

      {tab === 'alerts' && (
        <Panel title="Alert Center" subtitle="Dedup · cooldown · ACK · IN_APP provider (email/Telegram optional)">
          {alerts.loading && <LoadingState />}
          {alerts.error && <ErrorState message={alerts.error} />}
          {!alerts.loading && alerts.data && (
            <>
              <p className="permission-note">Providers: {JSON.stringify(alerts.data.providers)}</p>
              <AlertRows items={(alerts.data.items as Array<Record<string, unknown>>) ?? []} onAck={async (id) => {
                await phaseFifteenApi.ackAlert(id); alerts.reload()
              }} canAck={can('observability.operate')} />
            </>
          )}
        </Panel>
      )}

      {tab === 'validation' && (
        <Panel title="DEMO Validation Lab" subtitle="Forward testing ≠ backtest · evidence labels separate · AI shadow advisory only">
          {lab.loading && <LoadingState />}
          {lab.error && <ErrorState message={lab.error} />}
          {lab.data && (
            <pre className="code-block" style={{ whiteSpace: 'pre-wrap' }}>{JSON.stringify(lab.data, null, 2)}</pre>
          )}
          {!lab.loading && !lab.data && <EmptyState title="No lab data" detail="Open this tab to load validation sessions." />}
        </Panel>
      )}

      {tab === 'scorecard' && (
        <Panel title="Operational readiness scorecard" subtitle="NOT strategy profit · NO automatic safe-for-real-money">
          {scorecard.loading && <LoadingState />}
          {scorecard.data && <pre className="code-block" style={{ whiteSpace: 'pre-wrap' }}>{JSON.stringify(scorecard.data, null, 2)}</pre>}
        </Panel>
      )}

      {tab === 'incidents' && (
        <Panel title="Incidents">
          {incidents.loading && <LoadingState />}
          {incidents.data && Array.isArray(incidents.data) && incidents.data.length === 0 && <EmptyState title="No incidents" detail="Ops incidents will appear here." />}
          {incidents.data && Array.isArray(incidents.data) && incidents.data.length > 0 && (
            <DataTable
              columns={['ID', 'Title', 'Severity', 'Status', 'Opened']}
              rows={(incidents.data as Array<Record<string, unknown>>).map((row) => [
                value(row.public_id), value(row.title), value(row.severity), value(row.status), value(row.opened_at),
              ])}
            />
          )}
        </Panel>
      )}

      {['risk', 'execution', 'reconciliation', 'queue', 'server', 'comparisons'].includes(tab) && (
        <Panel title={tabs.find(([id]) => id === tab)?.[1] ?? tab}>
          {(tab === 'risk' && risk.loading) || (tab === 'execution' && execution.loading) || (tab === 'reconciliation' && reconciliation.loading)
            || (tab === 'queue' && queue.loading) || (tab === 'server' && server.loading) || (tab === 'comparisons' && comparisons.loading)
            ? <LoadingState />
            : (
              <pre className="code-block" style={{ whiteSpace: 'pre-wrap' }}>
                {JSON.stringify(
                  tab === 'risk' ? risk.data
                    : tab === 'execution' ? execution.data
                      : tab === 'reconciliation' ? reconciliation.data
                        : tab === 'queue' ? queue.data
                          : tab === 'server' ? server.data
                            : comparisons.data,
                  null,
                  2,
                )}
              </pre>
            )}
        </Panel>
      )}

      <div className="mobile-emergency-bar">
        <button className="btn danger" disabled={busy === 'emergency'} onClick={emergencyPause}>
          <Pause size={16} /> Mobile emergency inspect
        </button>
      </div>
    </>
  )
}

function AlertRows({
  items,
  onAck,
  canAck,
}: {
  items: Array<Record<string, unknown>>
  onAck: (id: string) => Promise<void>
  canAck: boolean
}) {
  if (!items.length) return <EmptyState title="No alerts" detail="System alerts will appear here." />
  return (
    <DataTable
      columns={['Severity', 'Category', 'Title', 'Status', 'Count', 'Actions']}
      rows={items.map((row) => [
        value(row.severity),
        value(row.category),
        value(row.title),
        value(row.status),
        value(row.occurrence_count),
        canAck && row.status === 'OPEN' ? (
          <button key={String(row.public_id)} className="btn ghost" type="button" onClick={() => onAck(String(row.public_id))}>ACK</button>
        ) : '—',
      ])}
    />
  )
}
