import { useCallback, useEffect, useState } from 'react'
import { Pause, Play, RefreshCw, ShieldAlert, Siren } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseNineteenApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

type Tab = 'ops' | 'envs' | 'safe' | 'deploy' | 'workers' | 'queues' | 'security' | 'dr'

export function PhaseNineteenOpsControlCenter() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('ops')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [lastRefresh, setLastRefresh] = useState(() => new Date().toISOString())
  const [safeScope, setSafeScope] = useState('GLOBAL')
  const [safeReason, setSafeReason] = useState('Operator scoped safe mode')

  const ops = useService(useCallback(() => phaseNineteenApi.ops(), []))
  const readiness = useService(useCallback(() => phaseNineteenApi.tradingReadiness(), []))
  const envs = useService(useCallback(() => (tab === 'envs' ? phaseNineteenApi.environments() : Promise.resolve(null)), [tab]))
  const safeModes = useService(useCallback(() => (tab === 'safe' ? phaseNineteenApi.safeModes() : Promise.resolve(null)), [tab]))
  const deploy = useService(useCallback(() => (tab === 'deploy' ? phaseNineteenApi.deploy() : Promise.resolve(null)), [tab]))
  const workers = useService(useCallback(() => (tab === 'workers' ? phaseNineteenApi.workers() : Promise.resolve(null)), [tab]))
  const queues = useService(useCallback(() => (tab === 'queues' ? phaseNineteenApi.queues() : Promise.resolve(null)), [tab]))
  const security = useService(useCallback(() => (tab === 'security' ? phaseNineteenApi.security() : Promise.resolve(null)), [tab]))
  const dr = useService(useCallback(() => (tab === 'dr' ? phaseNineteenApi.dr() : Promise.resolve(null)), [tab]))

  useEffect(() => {
    const id = window.setInterval(() => {
      ops.reload()
      readiness.reload()
      setLastRefresh(new Date().toISOString())
    }, 30000)
    return () => window.clearInterval(id)
  }, [ops, readiness])

  const reload = () => {
    ops.reload(); readiness.reload(); setLastRefresh(new Date().toISOString())
    if (tab === 'envs') envs.reload()
    if (tab === 'safe') safeModes.reload()
    if (tab === 'deploy') deploy.reload()
    if (tab === 'workers') workers.reload()
    if (tab === 'queues') queues.reload()
    if (tab === 'security') security.reload()
    if (tab === 'dr') dr.reload()
  }

  const validateEnv = async () => {
    if (!can('hardening.manage')) return
    setBusy('env'); setErr(''); setMsg('')
    try {
      const data = await phaseNineteenApi.validateEnvironments({})
      setMsg(`Config validation ${data.ok ? 'OK' : 'FAILED'} — app=${value(data.app_environment)} broker=${value(data.broker_trade_mode)}`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Validation failed')
    } finally {
      setBusy('')
    }
  }

  const activateSafe = async () => {
    if (!can('hardening.operate')) return
    setBusy('safe'); setErr(''); setMsg('')
    try {
      await phaseNineteenApi.activateSafeMode({ scope: safeScope, reason: safeReason })
      setMsg(`Safe mode ${safeScope} activated`)
      setTab('safe')
      safeModes.reload()
      ops.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Safe mode failed')
    } finally {
      setBusy('')
    }
  }

  const clearSafe = async (id: string) => {
    if (!can('hardening.operate')) return
    setBusy(id); setErr(''); setMsg('')
    try {
      await phaseNineteenApi.clearSafeMode(id)
      setMsg('Safe mode cleared')
      safeModes.reload(); ops.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Clear failed')
    } finally {
      setBusy('')
    }
  }

  const registerDeploy = async () => {
    if (!can('hardening.deploy')) return
    setBusy('deploy'); setErr(''); setMsg('')
    try {
      const row = await phaseNineteenApi.registerDeploy({ version: `p19-${Date.now()}` })
      setMsg(`Deploy registered ${value(row.version)}`)
      deploy.reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Deploy register failed')
    } finally {
      setBusy('')
    }
  }

  const opsData = ops.data as Record<string, unknown> | null
  const readyData = readiness.data as Record<string, unknown> | null
  const trading = (readyData?.trading_readiness ?? opsData?.trading_readiness) as Record<string, unknown> | undefined
  const health = opsData?.health as Record<string, unknown> | undefined
  const activeSafe = (opsData?.active_safe_modes as Array<Record<string, unknown>> | undefined) ?? []

  return (
    <div className="space-y-6">
      <PageHeader
        title="Ops Control Center"
        description="Phase 19 production hardening — separated liveness / readiness / trading-readiness, scoped safe modes, trading-aware deploy. LIVE hard-blocked."
        actions={(
          <button type="button" className="btn-secondary" onClick={reload} disabled={!!busy}>
            <RefreshCw size={16} /> Refresh
          </button>
        )}
      />

      {(ops.error || readiness.error) && <ErrorState message={ops.error || readiness.error || ''} />}
      {err && <ErrorState message={err} />}
      {msg && <Panel title="Status" className="border-emerald-500/30 text-emerald-200">{msg}</Panel>}

      <div className="grid gap-4 md:grid-cols-4">
        <MetricCard label="Liveness" value={value((opsData?.liveness as Record<string, unknown> | undefined)?.status ?? readyData?.liveness)} />
        <MetricCard label="Readiness" value={value((opsData?.readiness as Record<string, unknown> | undefined)?.status ?? readyData?.readiness)} />
        <MetricCard label="Trading readiness (DEMO)" value={value(trading?.demo_path ?? trading?.demo ?? trading?.trading_readiness ?? '—')} />
        <MetricCard label="LIVE / LIVE_AUTO" value={`${value(trading?.live ?? readyData?.live ?? 'NOT_READY')} / DOES NOT EXIST`} />
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <MetricCard label="Health" value={value(health?.overall)} />
        <MetricCard label="New entries blocked" value={value(health?.new_entries_blocked)} />
        <MetricCard label="Active safe modes" value={value(activeSafe.length)} />
      </div>

      <Panel title="Views">
        <div className="mb-3 flex flex-wrap gap-2 text-xs text-[var(--muted)]">
          <span>Last refresh {value(lastRefresh)}</span>
          <StatusBadge tone="warning">Blind retry on UNKNOWN: NONE</StatusBadge>
          <StatusBadge tone="bad">LIVE hard-blocked</StatusBadge>
        </div>
        <div className="flex flex-wrap gap-2">
          {([
            ['ops', 'Overview'],
            ['envs', 'App vs Broker'],
            ['safe', 'Safe modes'],
            ['deploy', 'Deploy'],
            ['workers', 'Workers'],
            ['queues', 'Queues'],
            ['security', 'Security'],
            ['dr', 'DR / Restore'],
          ] as const).map(([id, label]) => (
            <button
              key={id}
              type="button"
              className={tab === id ? 'btn-primary' : 'btn-secondary'}
              onClick={() => setTab(id)}
            >
              {label}
            </button>
          ))}
        </div>
      </Panel>

      {tab === 'ops' && (
        <Panel title="Operator actions">
          {ops.loading && <LoadingState />}
          {!ops.loading && !opsData && <EmptyState title="No ops payload" detail="Ops control center data will appear after load." />}
          {opsData && (
            <div className="space-y-4">
              <p className="text-sm text-[var(--muted)]">
                Hardening {value(opsData.hardening_version)} — Phase {value(opsData.phase)}. Phase 10 remains sole execution authority.
              </p>
              <div className="flex flex-wrap gap-2">
                <button type="button" className="btn-secondary" disabled={!can('hardening.manage') || busy === 'env'} onClick={validateEnv}>
                  <ShieldAlert size={16} /> Validate config
                </button>
                <button type="button" className="btn-primary" disabled={!can('hardening.operate') || busy === 'safe'} onClick={activateSafe}>
                  <Siren size={16} /> Activate scoped safe mode
                </button>
              </div>
              <div className="grid gap-3 md:grid-cols-2">
                <label className="text-sm">
                  Scope
                  <select className="input mt-1" value={safeScope} onChange={(e) => setSafeScope(e.target.value)}>
                    {((opsData.scoped_safe_mode_kinds as string[]) ?? ['GLOBAL']).map((s) => (
                      <option key={s} value={s}>{s}</option>
                    ))}
                  </select>
                </label>
                <label className="text-sm">
                  Reason
                  <input className="input mt-1" value={safeReason} onChange={(e) => setSafeReason(e.target.value)} />
                </label>
              </div>
            </div>
          )}
        </Panel>
      )}

      {tab === 'envs' && (
        <Panel title="App vs broker environments">
          {envs.loading && <LoadingState />}
          {envs.error && <ErrorState message={envs.error} />}
          {envs.data && (
            <div className="space-y-3 text-sm">
              <MetricCard label="App environment" value={value((envs.data as Record<string, unknown>).app_environment)} />
              <MetricCard label="Broker trade mode" value={value((envs.data as Record<string, unknown>).broker_trade_mode)} />
              <p className="text-[var(--muted)]">{value((envs.data as Record<string, unknown>).note)}</p>
              <button type="button" className="btn-secondary" disabled={!can('hardening.manage') || !!busy} onClick={validateEnv}>
                Re-validate
              </button>
            </div>
          )}
        </Panel>
      )}

      {tab === 'safe' && (
        <Panel title="Scoped safe modes">
          {safeModes.loading && <LoadingState />}
          {safeModes.error && <ErrorState message={safeModes.error} />}
          {Array.isArray(safeModes.data) && (
            <DataTable
              columns={['Scope', 'Ref', 'Active', 'Reason', 'Actions']}
              rows={(safeModes.data as Array<Record<string, unknown>>).map((row) => [
                value(row.scope),
                value(row.scope_ref),
                value(row.active),
                value(row.reason),
                row.active ? (
                  <button key={String(row.public_id)} type="button" className="btn-secondary" disabled={busy === String(row.public_id)} onClick={() => clearSafe(String(row.public_id))}>
                    <Play size={14} /> Clear
                  </button>
                ) : '—',
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'deploy' && (
        <Panel title="Trading-aware deploy">
          {deploy.loading && <LoadingState />}
          {deploy.error && <ErrorState message={deploy.error} />}
          <button type="button" className="btn-primary mb-3" disabled={!can('hardening.deploy') || !!busy} onClick={registerDeploy}>
            <Pause size={16} /> Register version
          </button>
          {deploy.data && (
            <DataTable
              columns={['Version', 'Status', 'Maintenance', 'Trading paused', 'Reconcile before resume']}
              rows={(((deploy.data as Record<string, unknown>).versions as Array<Record<string, unknown>>) ?? []).map((v) => [
                value(v.version),
                value(v.status),
                value(v.maintenance_mode),
                value(v.trading_paused),
                value(v.reconcile_before_resume),
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'workers' && (
        <Panel title="Supervised workers">
          {workers.loading && <LoadingState />}
          {workers.error && <ErrorState message={workers.error} />}
          {workers.data && (
            <>
              <p className="mb-2 text-sm text-[var(--muted)]">Restart reconcile required before trading resume. Blind retry on UNKNOWN: NONE.</p>
              <DataTable
                columns={['Kind', 'Label', 'Status', 'Graceful', 'Reconcile done']}
                rows={(((workers.data as Record<string, unknown>).workers as Array<Record<string, unknown>>) ?? []).map((w) => [
                  value(w.worker_kind),
                  value(w.label),
                  value(w.status),
                  value(w.graceful_shutdown),
                  value(w.reconcile_completed),
                ])}
              />
            </>
          )}
        </Panel>
      )}

      {tab === 'queues' && (
        <Panel title="Queues / DLQ">
          {queues.loading && <LoadingState />}
          {queues.error && <ErrorState message={queues.error} />}
          {queues.data && (
            <div className="grid gap-4 md:grid-cols-4">
              <MetricCard label="Pending" value={value((queues.data as Record<string, unknown>).pending)} />
              <MetricCard label="Running" value={value((queues.data as Record<string, unknown>).running)} />
              <MetricCard label="Done" value={value((queues.data as Record<string, unknown>).done)} />
              <MetricCard label="Dead letter" value={value((queues.data as Record<string, unknown>).dead)} />
            </div>
          )}
        </Panel>
      )}

      {tab === 'security' && (
        <Panel title="Security posture">
          {security.loading && <LoadingState />}
          {security.error && <ErrorState message={security.error} />}
          {security.data && (
            <pre className="overflow-auto rounded-lg bg-black/30 p-3 text-xs text-[var(--muted)]">
              {JSON.stringify(security.data, null, 2)}
            </pre>
          )}
        </Panel>
      )}

      {tab === 'dr' && (
        <Panel title="Disaster recovery">
          {dr.loading && <LoadingState />}
          {dr.error && <ErrorState message={dr.error} />}
          {dr.data && (
            <pre className="overflow-auto rounded-lg bg-black/30 p-3 text-xs text-[var(--muted)]">
              {JSON.stringify(dr.data, null, 2)}
            </pre>
          )}
          <p className="mt-2 text-sm text-[var(--muted)]">Full VPS restore drill: PENDING MANUAL. Isolated restore harness available via API.</p>
        </Panel>
      )}
    </div>
  )
}
