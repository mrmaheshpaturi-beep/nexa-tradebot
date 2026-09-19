import { useCallback, useEffect, useState } from 'react'
import { FlaskConical, GitCompare, ShieldCheck, RefreshCw } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseSixteenApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

type Tab = 'registry' | 'evidence' | 'approvals' | 'deployments' | 'lab' | 'compare' | 'lifecycle'

export function PhaseSixteenGovernanceCenter() {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('registry')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [tokens, setTokens] = useState<Record<string, string> | null>(null)
  const [approvalId, setApprovalId] = useState('')
  const [selectedVersion, setSelectedVersion] = useState('')
  const [semver, setSemver] = useState('1.0.0')
  const [strategyKey, setStrategyKey] = useState('ema_trend')
  const [compareLeft, setCompareLeft] = useState('')
  const [compareRight, setCompareRight] = useState('')
  const [compareResult, setCompareResult] = useState<Record<string, unknown> | null>(null)

  const dash = useService(useCallback(() => phaseSixteenApi.dashboard(), []))
  const lab = useService(useCallback(() => (tab === 'lab' ? phaseSixteenApi.lab() : Promise.resolve(null)), [tab]))
  const lifecycle = useService(useCallback(() => (tab === 'lifecycle' ? phaseSixteenApi.lifecycle() : Promise.resolve(null)), [tab]))
  const health = useService(useCallback(() => phaseSixteenApi.health(), []))

  useEffect(() => {
    const id = window.setInterval(() => { dash.reload(); health.reload() }, 30000)
    return () => window.clearInterval(id)
  }, [dash, health])

  const reload = () => {
    dash.reload(); health.reload()
    if (tab === 'lab') lab.reload()
    if (tab === 'lifecycle') lifecycle.reload()
  }

  const versions = (dash.data?.versions as Array<Record<string, unknown>> | undefined) ?? []
  const approvals = (dash.data?.approvals as Array<Record<string, unknown>> | undefined) ?? []
  const deployments = (dash.data?.deployments as Array<Record<string, unknown>> | undefined) ?? []
  const candidates = (dash.data?.release_candidates as Array<Record<string, unknown>> | undefined) ?? []

  const register = async () => {
    if (!can('governance.manage')) return
    setBusy('register'); setErr(''); setMsg('')
    try {
      const v = await phaseSixteenApi.registerVersion({
        strategy_key: strategyKey,
        semantic_version: semver,
        configuration: { note: 'governance-ui' },
      })
      setSelectedVersion(String(v.public_id))
      setMsg(`Registered ${value(v.strategy_key)}@${value(v.semantic_version)} — DEMO governance only`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Register failed')
    } finally {
      setBusy('')
    }
  }

  const toReview = async () => {
    if (!selectedVersion || !can('governance.manage')) return
    setBusy('review'); setErr(''); setMsg('')
    try {
      await phaseSixteenApi.transition(selectedVersion, 'IN_REVIEW')
      const rc = await phaseSixteenApi.openReleaseCandidate(selectedVersion, { title: `RC ${strategyKey}@${semver}` })
      setMsg(`Release candidate ${value(rc.public_id)} opened`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Transition failed')
    } finally {
      setBusy('')
    }
  }

  const buildEvidence = async () => {
    if (!selectedVersion || !can('governance.manage')) return
    setBusy('evidence'); setErr(''); setMsg('')
    try {
      const pkg = await phaseSixteenApi.buildEvidence(selectedVersion, {
        evidence_label: 'DEMO',
        sample_count: 12,
        phase12_analytics_refs: [{ source: 'ui' }],
        metrics: { note: 'manual DEMO package' },
      })
      setMsg(`Evidence ${value(pkg.public_id)} — insufficient=${value(pkg.insufficient_samples)} auto_approve=${value(pkg.can_auto_approve)}`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Evidence failed')
    } finally {
      setBusy('')
    }
  }

  const startApproval = async (action: string) => {
    if (!selectedVersion || !can('governance.approve')) return
    setBusy('approval'); setErr(''); setMsg('')
    try {
      const data = await phaseSixteenApi.startApproval(selectedVersion, action)
      const ap = data.approval as Record<string, unknown>
      const tok = data.tokens as Record<string, string>
      setApprovalId(String(ap.public_id))
      setTokens(tok)
      setMsg(`Two-step approval started for ${action} — tokens bound & single-use · DEMO only`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Approval start failed')
    } finally {
      setBusy('')
    }
  }

  const consumeStep = async (step: 1 | 2) => {
    if (!approvalId || !tokens || !can('governance.approve')) return
    setBusy(`step${step}`); setErr(''); setMsg('')
    try {
      const data = await phaseSixteenApi.approvalStep(approvalId, {
        step,
        token: tokens[`step${step}`],
        nonce: tokens[`step${step}_nonce`],
        idempotency_key: `${approvalId}-step${step}-${Date.now()}`,
      })
      setMsg(`Step ${step} ${data.completed ? 'COMPLETE' : 'accepted'} — ${value((data.version as Record<string, unknown> | undefined)?.lifecycle_state)}`)
      reload()
    } catch (e) {
      setErr(firstValidationError(e) || `Step ${step} failed`)
    } finally {
      setBusy('')
    }
  }

  const runLab = async (mode: string) => {
    if (!can('governance.lab')) return
    setBusy('lab'); setErr(''); setMsg('')
    try {
      const exp = await phaseSixteenApi.runLab({
        lab_mode: mode,
        version_public_id: selectedVersion || undefined,
        parameters: mode === 'COST_SENSITIVITY' ? { spreads: [0.5, 1, 2] } : { shocks: [0.5, 1, 2] },
      })
      setMsg(`Lab ${mode} ${value(exp.status)} — can_deploy=${value(exp.can_deploy)} (always false)`)
      lab.reload(); reload()
    } catch (e) {
      setErr(firstValidationError(e) || 'Lab failed')
    } finally {
      setBusy('')
    }
  }

  const doCompare = async () => {
    if (!compareLeft || !compareRight || !can('governance.view')) return
    setBusy('compare'); setErr(''); setMsg('')
    try {
      const cmp = await phaseSixteenApi.compare(compareLeft, compareRight)
      setCompareResult(cmp)
      setMsg('Comparison saved')
    } catch (e) {
      setErr(firstValidationError(e) || 'Compare failed')
    } finally {
      setBusy('')
    }
  }

  if (dash.loading && !dash.data) return <LoadingState />
  if (dash.error) return <ErrorState message={dash.error} />

  const h = health.data ?? {}

  return (
    <div className="page-stack">
      <PageHeader
        title="Strategy Governance"
        description="Release engineering · evidence · two-step human approval · DEMO-only promotion · Strategy Lab"
        actions={(
          <button type="button" className="btn ghost" onClick={reload} disabled={!!busy}>
            <RefreshCw size={16} /> Refresh
          </button>
        )}
      />

      <div className="danger-banner" role="status">
        DEMO-only deployments via Phase 14 AutomationProfile. LIVE and live-auto controls do not exist.
        AI cannot approve, deploy, or change active config. Governance never sends broker orders.
      </div>

      <div className="metric-grid">
        <MetricCard label="Phase" value={value(h.phase ?? 16)} />
        <MetricCard label="P16 broker writes" value={value(h.order_send_phase16 ?? 0)} />
        <MetricCard label="AI may approve" value={value(h.ai_may_approve ?? false)} />
        <MetricCard label="Deploy targets" value="DEMO_AUTO" />
      </div>

      {(msg || err) && (
        <Panel title={err ? 'Error' : 'Status'}>
          <p className={err ? 'error-inline' : 'success-inline'}>{err || msg}</p>
        </Panel>
      )}

      <div className="tab-row">
        {([
          ['registry', 'Registry'],
          ['evidence', 'Evidence'],
          ['approvals', 'Approvals'],
          ['deployments', 'Deployments'],
          ['lab', 'Strategy Lab'],
          ['compare', 'Compare'],
          ['lifecycle', 'Lifecycle'],
        ] as const).map(([id, label]) => (
          <button key={id} type="button" className={`btn ${tab === id ? 'primary' : 'ghost'}`} onClick={() => setTab(id)}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'registry' && (
        <Panel title="Strategy registry" subtitle="Immutable semantic versions + code/config hashes">
          <div className="form-row">
            <label>
              Strategy key
              <input value={strategyKey} onChange={(e) => setStrategyKey(e.target.value)} />
            </label>
            <label>
              Semantic version
              <input value={semver} onChange={(e) => setSemver(e.target.value)} />
            </label>
            <button type="button" className="btn primary" disabled={!!busy || !can('governance.manage')} onClick={register}>
              Register version
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !selectedVersion || !can('governance.manage')} onClick={toReview}>
              Open release candidate
            </button>
          </div>
          {versions.length === 0 ? <EmptyState title="No governed versions" detail="Register an immutable semantic version to begin." /> : (
            <DataTable
              columns={['Public ID', 'Strategy', 'SemVer', 'State', 'Code hash', 'Select']}
              rows={versions.map((v) => [
                value(v.public_id),
                value(v.strategy_key),
                value(v.semantic_version),
                <StatusBadge key={String(v.public_id)} tone="info">{String(v.lifecycle_state)}</StatusBadge>,
                value(String(v.code_hash ?? '').slice(0, 12)),
                <button key={`s-${v.public_id}`} type="button" className="btn ghost" onClick={() => setSelectedVersion(String(v.public_id))}>
                  {selectedVersion === v.public_id ? 'Selected' : 'Select'}
                </button>,
              ])}
            />
          )}
          {candidates.length > 0 && (
            <p className="muted">Open RCs: {candidates.filter((c) => c.status === 'OPEN').length}</p>
          )}
        </Panel>
      )}

      {tab === 'evidence' && (
        <Panel title="Evidence packages" subtitle="Phase 12 analytics + Phase 15 forward validation · insufficient samples block auto-approve">
          <button type="button" className="btn primary" disabled={!!busy || !selectedVersion || !can('governance.manage')} onClick={buildEvidence}>
            <ShieldCheck size={16} /> Build DEMO evidence package
          </button>
          <p className="muted">Selected version: {selectedVersion || '—'}</p>
        </Panel>
      )}

      {tab === 'approvals' && (
        <Panel title="Two-step human approvals" subtitle="Bound single-use tokens · staleness · replay protection">
          <div className="form-row">
            <button type="button" className="btn primary" disabled={!!busy || !selectedVersion || !can('governance.approve')} onClick={() => startApproval('APPROVE_CANDIDATE')}>
              Start APPROVE_CANDIDATE
            </button>
            <button type="button" className="btn primary" disabled={!!busy || !selectedVersion || !can('governance.approve')} onClick={() => startApproval('PROMOTE_DEMO')}>
              Start PROMOTE_DEMO
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !selectedVersion || !can('governance.approve')} onClick={() => startApproval('SUSPEND')}>
              Suspend
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !selectedVersion || !can('governance.approve')} onClick={() => startApproval('ROLLBACK')}>
              Rollback
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !approvalId || !tokens} onClick={() => consumeStep(1)}>
              Consume step 1
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !approvalId || !tokens} onClick={() => consumeStep(2)}>
              Consume step 2
            </button>
          </div>
          {approvals.length === 0 ? <EmptyState title="No approvals yet" detail="Start a two-step human approval for the selected version." /> : (
            <DataTable
              columns={['ID', 'Action', 'Status', 'Steps']}
              rows={approvals.slice(0, 15).map((a) => [
                value(a.public_id),
                value(a.action),
                <StatusBadge key={String(a.public_id)} tone="info">{String(a.status)}</StatusBadge>,
                `${value(a.completed_steps)}/${value(a.required_steps)}`,
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'deployments' && (
        <Panel title="DEMO deployments" subtitle="Integrated with Phase 14 AutomationProfile · positions preserved on rollback">
          {deployments.length === 0 ? <EmptyState title="No deployments" detail="DEMO_AUTO promotions appear here after two-step approval." /> : (
            <DataTable
              columns={['ID', 'Target', 'Status', 'Positions preserved', 'Deployed']}
              rows={deployments.map((d) => [
                value(d.public_id),
                value(d.target),
                <StatusBadge key={String(d.public_id)} tone="info">{String(d.status)}</StatusBadge>,
                value(d.positions_preserved),
                value(d.deployed_at),
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'lab' && (
        <Panel title="Strategy Lab" subtitle="Robustness / cost sensitivity · isolated · never mutates active config · never deploys">
          <div className="form-row">
            <button type="button" className="btn primary" disabled={!!busy || !can('governance.lab')} onClick={() => runLab('ROBUSTNESS')}>
              <FlaskConical size={16} /> Robustness
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !can('governance.lab')} onClick={() => runLab('COST_SENSITIVITY')}>
              Cost sensitivity
            </button>
            <button type="button" className="btn ghost" disabled={!!busy || !can('governance.lab')} onClick={() => runLab('SHADOW')}>
              Shadow
            </button>
          </div>
          {lab.data && (
            <p className="muted">
              can_deploy={value(lab.data.can_deploy)} · mutates_active_config={value(lab.data.mutates_active_config)} · live auto controls absent={value(lab.data.live_auto_controls === false)}
            </p>
          )}
          {((lab.data?.experiments as Array<Record<string, unknown>> | undefined) ?? []).length > 0 && (
            <DataTable
              columns={['ID', 'Mode', 'Status', 'Can deploy']}
              rows={((lab.data?.experiments as Array<Record<string, unknown>>) ?? []).map((e) => [
                value(e.public_id),
                value(e.lab_mode),
                value(e.status),
                value(e.can_deploy),
              ])}
            />
          )}
        </Panel>
      )}

      {tab === 'compare' && (
        <Panel title="Version comparison / diffs" subtitle="Code hash · config hash · parameter diff">
          <div className="form-row">
            <label>
              Left public_id
              <input value={compareLeft} onChange={(e) => setCompareLeft(e.target.value)} />
            </label>
            <label>
              Right public_id
              <input value={compareRight} onChange={(e) => setCompareRight(e.target.value)} />
            </label>
            <button type="button" className="btn primary" disabled={!!busy} onClick={doCompare}>
              <GitCompare size={16} /> Diff
            </button>
          </div>
          {compareResult && (
            <pre className="code-block">{JSON.stringify(compareResult.diff ?? compareResult, null, 2)}</pre>
          )}
        </Panel>
      )}

      {tab === 'lifecycle' && (
        <Panel title="Allowed lifecycle transitions" subtitle="Illegal jumps rejected">
          {lifecycle.loading && <LoadingState />}
          {lifecycle.data && (
            <pre className="code-block">{JSON.stringify(lifecycle.data.allowed_transitions, null, 2)}</pre>
          )}
        </Panel>
      )}
    </div>
  )
}
