import { useCallback, useState } from 'react'
import { AlertTriangle, CheckCircle2, RefreshCw, ShieldAlert } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseTenApi, phaseTwoApi, phaseThreeApi } from '../api/services'
import { useAuth } from '../auth/authState'
import {
  DataTable, EmptyState, ErrorState, LoadingState, MetricCard, PageHeader, Panel, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const value = (input: unknown) => (input === null || input === undefined || input === '' ? '—' : String(input))

export function PhaseTenExecutionConsole() {
  const { can } = useAuth()
  const [busy, setBusy] = useState('')
  const [actionError, setActionError] = useState('')
  const [actionMsg, setActionMsg] = useState('')
  const [intentId, setIntentId] = useState('')
  const [challengeToken, setChallengeToken] = useState('')
  const [confirmToken, setConfirmToken] = useState('')
  const [confirmationId, setConfirmationId] = useState('')
  const [step, setStep] = useState(0)

  const dashboard = useService(useCallback(() => phaseTenApi.dashboard(), []))
  const health = useService(useCallback(() => phaseTenApi.health(), []))
  const status = useService(useCallback(() => phaseTwoApi.status(), []))
  const intents = useService(useCallback(() => phaseThreeApi.tradeIntents(), []))

  const reload = () => { dashboard.reload(); health.reload(); status.reload(); intents.reload() }

  const startConfirm = async () => {
    if (!can('execution.confirm') || !intentId) return
    setBusy('step1'); setActionError(''); setActionMsg('')
    try {
      const result = await phaseTenApi.startConfirmation(intentId, `ui-cnf-${Date.now()}`)
      setConfirmationId(result.confirmation.public_id)
      setChallengeToken(result.challenge_token)
      setStep(1)
      setActionMsg('Step 1 complete — DEMO challenge issued. Auto Demo remains OFF.')
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const completeConfirm = async () => {
    if (!can('execution.confirm') || !confirmationId || !challengeToken) return
    setBusy('step2'); setActionError('')
    try {
      const result = await phaseTenApi.completeConfirmation(confirmationId, challengeToken)
      setConfirmToken(result.confirm_token)
      setStep(2)
      setActionMsg('Step 2 complete — confirm token ready for DEMO submit.')
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const submitDemo = async () => {
    if (!can('execution.execute') || !intentId || !confirmationId || !confirmToken) return
    setBusy('submit'); setActionError('')
    try {
      const result = await phaseTenApi.submit({
        trade_intent_public_id: intentId,
        confirmation_public_id: confirmationId,
        confirm_token: confirmToken,
        idempotency_key: `ui-exe-${Date.now()}`,
      })
      setStep(3)
      setActionMsg(`DEMO submit ${result.replayed ? 'replayed' : 'accepted'} — command ${result.command.public_id}`)
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const recover = async (commandId: string) => {
    if (!can('execution.recover')) return
    setBusy(`recover-${commandId}`); setActionError('')
    try {
      await phaseTenApi.recover(commandId)
      setActionMsg('UNKNOWN command recovered without blind retry.')
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  const reconcile = async () => {
    if (!can('execution.reconcile')) return
    setBusy('reconcile'); setActionError('')
    try {
      await phaseTenApi.reconcile()
      setActionMsg('DEMO reconciliation run completed.')
      reload()
    } catch (error) {
      setActionError(firstValidationError(error))
    } finally {
      setBusy('')
    }
  }

  if (dashboard.loading || health.loading) return <LoadingState />
  if (dashboard.error || health.error) return <ErrorState message={dashboard.error || health.error || 'Failed'} onRetry={reload} />

  const demoEnabled = Boolean(status.data?.allow_demo_execution)
  const rows = (dashboard.data?.commands ?? []) as Array<Record<string, unknown>>
  const intentOptions = ((intents.data as { data?: Array<Record<string, unknown>> } | Array<Record<string, unknown>> | null)?.data
    ?? (Array.isArray(intents.data) ? intents.data : [])
    ?? []) as Array<Record<string, unknown>>
  const demoIntents = intentOptions.filter((row) => row.environment === 'DEMO')

  return (
    <div className="page-stack">
      <PageHeader
        title="DEMO Execution Engine"
        subtitle="Phase 10 — manual two-step DEMO confirmation. LIVE hard-fail. Auto Demo OFF."
        actions={<button type="button" className="ghost-button" onClick={reload}><RefreshCw size={16} /> Refresh</button>}
      />

      <div className="safety-strip demo-execution-banner" role="status">
        <ShieldAlert size={16} />
        <strong>DEMO ONLY</strong>
        <span> — not live funds. Two-step confirmation required. Auto Demo is OFF. Sole order_send path is bridge-authorized.</span>
      </div>

      <div className="metric-grid">
        <MetricCard label="DEMO execution" value={demoEnabled ? 'ENABLED (manual)' : 'DISABLED'} detail="allow_demo_execution" />
        <MetricCard label="Auto Demo" value="OFF" detail="Locked false" />
        <MetricCard label="LIVE" value="HARD FAIL" detail="Multi-layer reject" />
        <MetricCard label="UNKNOWN cmds" value={String(dashboard.data?.unknown_count ?? 0)} detail="No blind retry" />
        <MetricCard label="Confirm step" value={String(step)} detail="0 idle · 1 challenge · 2 token · 3 submitted" />
        <MetricCard label="order_send" value="1 path" detail="execution.py::authorized_order_send" />
      </div>

      <Panel title="Two-step DEMO confirmation" subtitle="Never hides that this is DEMO">
        {!can('execution.confirm') && <EmptyState title="Missing execution.confirm permission" />}
        {can('execution.confirm') && (
          <div className="form-grid">
            <label>
              <span>DEMO trade intent</span>
              <select value={intentId} onChange={(event) => setIntentId(event.target.value)} aria-label="DEMO trade intent">
                <option value="">Select DEMO intent…</option>
                {demoIntents.map((intent) => (
                  <option key={String(intent.public_id)} value={String(intent.public_id)}>
                    {String(intent.public_id)} · {String(intent.status)}
                  </option>
                ))}
              </select>
            </label>
            <div className="button-row">
              <button type="button" disabled={!intentId || busy === 'step1'} onClick={startConfirm}>1. Start confirmation</button>
              <button type="button" disabled={step < 1 || busy === 'step2'} onClick={completeConfirm}>2. Confirm challenge</button>
              <button type="button" className="primary" disabled={!can('execution.execute') || step < 2 || busy === 'submit'} onClick={submitDemo}>
                Submit DEMO order
              </button>
            </div>
            {challengeToken && <p className="muted">Challenge issued (hidden after use). Confirmation: {confirmationId}</p>}
            {confirmToken && <p className="muted"><CheckCircle2 size={14} /> Confirm token ready for sole DEMO submit.</p>}
          </div>
        )}
        {actionMsg && <p className="success-inline">{actionMsg}</p>}
        {actionError && <p className="error-inline"><AlertTriangle size={14} /> {actionError}</p>}
      </Panel>

      <Panel title="DEMO commands & recovery" subtitle="UNKNOWN states never auto-retry order_send">
        <div className="button-row" style={{ marginBottom: '0.75rem' }}>
          <button type="button" disabled={!can('execution.reconcile') || busy === 'reconcile'} onClick={reconcile}>Run reconciliation</button>
        </div>
        {rows.length === 0 ? <EmptyState title="No DEMO commands yet" /> : (
          <DataTable
            columns={['Command', 'Status', 'Submission', 'Blind retry', 'Actions']}
            rows={rows.map((row) => [
              value(row.public_id),
              <StatusBadge key="st" status={String(row.status)} />,
              value(row.submission_state),
              row.blind_retry_forbidden ? 'Forbidden' : '—',
              row.submission_state === 'UNKNOWN' && can('execution.recover') ? (
                <button key="r" type="button" disabled={busy.startsWith('recover')} onClick={() => recover(String(row.public_id))}>Recover</button>
              ) : '—',
            ])}
          />
        )}
      </Panel>

      <Panel title="Engine health" subtitle="Phase 10 contract">
        <pre className="code-block">{JSON.stringify({
          health: health.data,
          execution_engine: status.data?.execution_engine,
          allow_demo_execution: status.data?.allow_demo_execution,
          auto_demo_execution: status.data?.auto_demo_execution,
          allow_live_execution: status.data?.allow_live_execution,
        }, null, 2)}</pre>
      </Panel>
    </div>
  )
}
