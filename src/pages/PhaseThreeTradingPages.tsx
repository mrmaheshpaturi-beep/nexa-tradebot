import { useCallback, useRef, useState } from 'react'
import { AlertOctagon, Check, ChevronRight, Play, X } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { makePhaseThreeIdentity, phaseThreeApi, phaseTwoApi } from '../api/services'
import type { ExecutionCommand, Order, OrderType, Position, RiskDecision, Signal, TradeIntent } from '../api/types'
import { useAuth } from '../auth/authState'
import {
  ConfirmationDialog, DataTable, DirectionBadge, EmptyState, EnvironmentBadge, ErrorState,
  LoadingState, PageHeader, Panel, PnLDisplay, StatusBadge,
} from '../components/ui'
import { useService } from '../hooks/useService'

const n = (value: string | number | null | undefined) => Number(value ?? 0)
const date = (value: string | null | undefined) => value ? new Date(value).toLocaleString() : '—'
const value = (input: unknown) => input === null || input === undefined || input === '' ? '—' : String(input)
const pendingOrder = (order: Order) => order.environment === 'SIMULATION' && order.status === 'ACCEPTED' && order.order_type !== 'MARKET'

function FieldList({ fields }: { fields: Array<[string, unknown]> }) {
  return <div className="detail-list">{fields.map(([label, field]) =>
    <div key={label}><span>{label}</span><strong>{value(field)}</strong></div>)}</div>
}

function LifecycleTimeline({ intent }: { intent: TradeIntent }) {
  const command = intent.execution_command
  const order = command?.order
  const deal = order?.deals?.[0]
  const position = order?.position
  const stages = [
    { name: 'SIGNAL', id: intent.signal?.public_id, status: intent.signal?.status, at: intent.signal?.generated_at, reason: intent.signal?.explanation },
    { name: 'TRADE INTENT', id: intent.public_id, status: intent.status, at: intent.created_at, reason: intent.comment },
    { name: 'RISK DECISION', id: intent.risk_decision?.public_id, status: intent.risk_decision?.status, at: intent.risk_decision?.evaluated_at, reason: intent.risk_decision?.reason },
    { name: 'EXECUTION COMMAND', id: command?.public_id, status: command?.status, at: command?.requested_at, reason: command?.safe_error },
    { name: 'ORDER', id: order?.public_id, status: order?.status, at: order?.requested_at, reason: order?.status === 'REJECTED' ? 'Simulation order rejected' : null },
    { name: 'DEAL', id: deal?.public_id, status: deal?.type, at: deal?.executed_at, reason: null },
    { name: 'POSITION', id: position?.public_id, status: position?.status, at: position?.opened_at, reason: null },
  ]
  return <div className="lifecycle-timeline" aria-label="Trade lifecycle">
    {stages.map((stage, index) => <article key={stage.name} className={stage.id ? 'complete' : 'pending'}>
      <span className="lifecycle-index">{index + 1}</span>
      <div><small>{stage.name}</small><strong>{stage.id ?? 'NOT CREATED'}</strong>
        <p>{stage.status ?? 'Awaiting prior stage'} · {date(stage.at)}</p>{stage.reason && <em>{stage.reason}</em>}</div>
      <StatusBadge tone={stage.id ? 'info' : 'neutral'}>SIMULATION</StatusBadge>
    </article>)}
  </div>
}

export function TradeLifecycleDrawer({ intentPublicId, onClose }: { intentPublicId: string; onClose: () => void }) {
  const result = useService(useCallback(() => phaseThreeApi.tradeIntent(intentPublicId), [intentPublicId]))
  return <aside className="drawer lifecycle-drawer" aria-label="Trade lifecycle details">
    <button className="drawer-close" aria-label="Close lifecycle details" onClick={onClose}><X /></button>
    <span className="eyebrow">PHASE 3 / SIMULATION</span><h2>Trade Lifecycle</h2>
    <p>Persistent records only. No stage transmits to a broker.</p>
    {result.loading ? <LoadingState /> : result.error || !result.data
      ? <ErrorState message={result.error ?? 'Lifecycle unavailable.'} />
      : <><LifecycleTimeline intent={result.data} />
        <FieldList fields={[
          ['Intent origin', result.data.origin], ['Side', result.data.side], ['Order type', result.data.order_type],
          ['Requested volume', result.data.requested_volume], ['Entry', result.data.requested_entry],
          ['Stop loss', result.data.stop_loss], ['Take profit', result.data.take_profit],
          ['Risk reason code', result.data.risk_decision?.reason_code],
        ]} /></>}
  </aside>
}

type ManualStage = { label: string; status: string; publicId?: string; detail: string }

export function PersistentManualTrading() {
  const reference = useService(useCallback(() => Promise.all([
    phaseThreeApi.accounts(), phaseThreeApi.instruments(),
  ]), []))
  const { can } = useAuth()
  const [accountId, setAccountId] = useState('')
  const [instrumentId, setInstrumentId] = useState('')
  const [side, setSide] = useState<'BUY' | 'SELL'>('BUY')
  const [orderType, setOrderType] = useState<OrderType>('MARKET')
  const [volume, setVolume] = useState(.01)
  const [entry, setEntry] = useState('')
  const [stopLoss, setStopLoss] = useState('')
  const [takeProfit, setTakeProfit] = useState('')
  const [risk, setRisk] = useState(1)
  const [comment, setComment] = useState('')
  const [confirm, setConfirm] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [stages, setStages] = useState<ManualStage[]>([])
  const [lifecycleId, setLifecycleId] = useState('')
  const [showLifecycle, setShowLifecycle] = useState(false)
  const [identity, setIdentity] = useState(() => ({ intent: makePhaseThreeIdentity('intent'), execute: makePhaseThreeIdentity('execute') }))
  const accounts = reference.data?.[0].data ?? []
  const instruments = reference.data?.[1].data.filter((item) => item.is_enabled) ?? []
  const selectedAccount = accountId || accounts[0]?.public_id || ''
  const selectedInstrument = instrumentId || instruments[0]?.public_id || ''

  const submit = async () => {
    if (submitting) return
    setSubmitting(true); setError(''); setStages([])
    try {
      const intent = await phaseThreeApi.createTradeIntent({
        account_public_id: selectedAccount, instrument_public_id: selectedInstrument,
        idempotency_key: identity.intent, origin: 'MANUAL', side, order_type: orderType,
        requested_volume: volume, requested_entry: entry ? Number(entry) : null,
        stop_loss: stopLoss ? Number(stopLoss) : null, take_profit: takeProfit ? Number(takeProfit) : null,
        risk_percent: risk, comment,
      })
      setLifecycleId(intent.public_id)
      setShowLifecycle(false)
      setStages([{ label: 'TRADE INTENT', status: intent.status, publicId: intent.public_id, detail: 'DRAFT persisted, then transitioned to PENDING_RISK.' }])
      const evaluated = await phaseThreeApi.evaluateTradeIntent(intent.public_id)
      const decision = evaluated.risk_decision
      setStages((items) => [...items, {
        label: 'RISK DECISION', status: decision?.status ?? evaluated.status,
        publicId: decision?.public_id, detail: decision?.reason ?? 'Deterministic risk evaluation completed.',
      }])
      if (decision?.status !== 'APPROVED') {
        setError(`Simulation rejected at the risk gate: ${decision?.reason ?? 'The intent was not approved.'}`)
        return
      }
      const command = await phaseThreeApi.executeTradeIntent(intent.public_id, identity.execute)
      setStages((items) => [...items, {
        label: 'EXECUTION COMMAND', status: command.status, publicId: command.public_id,
        detail: `${command.order?.public_id ?? 'Order result available in lifecycle'} · SIMULATION adapter only.`,
      }])
      setIdentity({ intent: makePhaseThreeIdentity('intent'), execute: makePhaseThreeIdentity('execute') })
    } catch (caught) {
      setError(firstValidationError(caught))
    } finally {
      setSubmitting(false); setConfirm(false)
    }
  }

  if (reference.loading) return <LoadingState />
  if (reference.error) return <ErrorState message={reference.error} />
  return <>
    <PageHeader title="Manual Trading Terminal" description="Explicit intent → deterministic risk → simulation execution flow. No broker transmission." actions={<EnvironmentBadge />} />
    {error && <div className="danger-banner" role="alert"><AlertOctagon />{error}</div>}
    {stages.length > 0 && <Panel title="Lifecycle result" subtitle="Every persisted stage remains SIMULATION">
      <div className="lifecycle-summary">{stages.map((stage) => <div key={stage.label}>
        <StatusBadge tone={stage.status === 'APPROVED' || stage.status === 'COMPLETED' ? 'good' : stage.status.includes('REJECT') ? 'bad' : 'info'}>{stage.status}</StatusBadge>
        <p><strong>{stage.label}</strong><span>{stage.publicId}</span><small>{stage.detail}</small></p>
      </div>)}</div>
      {lifecycleId && <button className="btn ghost lifecycle-link" onClick={() => setShowLifecycle(true)}>View complete lifecycle <ChevronRight /></button>}
    </Panel>}
    <div className="ticket-layout"><Panel title="Simulation lifecycle ticket" subtitle="One confirmed action orchestrates three explicit backend calls">
      <div className="direction-toggle">
        <button type="button" className={side === 'BUY' ? 'buy active' : ''} onClick={() => { setSide('BUY'); if (orderType !== 'MARKET') setOrderType(orderType.includes('LIMIT') ? 'BUY_LIMIT' : 'BUY_STOP') }}>BUY</button>
        <button type="button" className={side === 'SELL' ? 'sell active' : ''} onClick={() => { setSide('SELL'); if (orderType !== 'MARKET') setOrderType(orderType.includes('LIMIT') ? 'SELL_LIMIT' : 'SELL_STOP') }}>SELL</button>
      </div>
      <div className="form-grid">
        <label className="field" htmlFor="manual-account"><span>Persisted simulation account</span><select id="manual-account" value={selectedAccount} onChange={(e) => setAccountId(e.target.value)}>{accounts.map((item) => <option value={item.public_id} key={item.public_id}>{item.name} · {item.environment}</option>)}</select></label>
        <label className="field" htmlFor="manual-instrument"><span>Persisted instrument</span><select id="manual-instrument" value={selectedInstrument} onChange={(e) => setInstrumentId(e.target.value)}>{instruments.map((item) => <option value={item.public_id} key={item.public_id}>{item.symbol} · {item.display_name}</option>)}</select></label>
        <label className="field" htmlFor="manual-order-type"><span>Order type</span><select id="manual-order-type" value={orderType} onChange={(e) => setOrderType(e.target.value as OrderType)}><option>MARKET</option><option>{side}_LIMIT</option><option>{side}_STOP</option></select></label>
        <label className="field" htmlFor="manual-volume"><span>Volume</span><input id="manual-volume" type="number" min=".01" step=".01" value={volume} onChange={(e) => setVolume(Number(e.target.value))} /></label>
        <label className="field" htmlFor="manual-entry"><span>{orderType === 'MARKET' ? 'Entry override (optional)' : 'Requested entry'}</span><input id="manual-entry" type="number" step="any" required={orderType !== 'MARKET'} value={entry} onChange={(e) => setEntry(e.target.value)} /></label>
        <label className="field" htmlFor="manual-risk"><span>Risk %</span><input id="manual-risk" type="number" min="0" max="100" step=".1" value={risk} onChange={(e) => setRisk(Number(e.target.value))} /></label>
        <label className="field" htmlFor="manual-stop-loss"><span>Stop loss</span><input id="manual-stop-loss" type="number" step="any" value={stopLoss} onChange={(e) => setStopLoss(e.target.value)} /></label>
        <label className="field" htmlFor="manual-take-profit"><span>Take profit</span><input id="manual-take-profit" type="number" step="any" value={takeProfit} onChange={(e) => setTakeProfit(e.target.value)} /></label>
        <label className="field wide" htmlFor="manual-comment"><span>Comment</span><input id="manual-comment" maxLength={255} value={comment} onChange={(e) => setComment(e.target.value)} /></label>
      </div>
      <button className={`btn ${side === 'BUY' ? 'buy' : 'sell'} persistence-submit`} disabled={!can('simulation_lifecycle.create') || !can('simulation_lifecycle.evaluate') || !can('simulation_lifecycle.execute') || !selectedAccount || !selectedInstrument || submitting || (orderType !== 'MARKET' && !entry)} onClick={() => setConfirm(true)}>
        <Play />{submitting ? 'SIMULATING…' : `SIMULATE ${side}`}
      </button>
      {!can('simulation_lifecycle.create') || !can('simulation_lifecycle.evaluate') || !can('simulation_lifecycle.execute')
        ? <p className="permission-note">Create, evaluate, and execute permissions are all required.</p> : null}
    </Panel><Panel title="Safety boundary" subtitle="Phase 3 simulation only">
      <FieldList fields={[['Environment', 'SIMULATION'], ['Broker transmission', 'FALSE'], ['Execution adapter', 'SIMULATION'], ['Intent idempotency key', identity.intent], ['Execution idempotency key', identity.execute]]} />
      <div className="warning-box"><AlertOctagon /><p><strong>No broker instruction</strong><span>Trading remains disabled. This action can only mutate the simulation ledger.</span></p></div>
    </Panel></div>
    <ConfirmationDialog open={confirm} title={`Confirm SIMULATE ${side}`} onCancel={() => setConfirm(false)} onConfirm={submit}>Create an intent, evaluate deterministic simulation risk, and execute only if approved. No broker transmission occurs.</ConfirmationDialog>
    {lifecycleId && showLifecycle && <TradeLifecycleDrawer intentPublicId={lifecycleId} onClose={() => setShowLifecycle(false)} />}
  </>
}

function signalEligible(signal: Signal) {
  return signal.environment === 'SIMULATION' && ['BUY', 'SELL'].includes(signal.direction)
    && ['GENERATED', 'VALID'].includes(signal.status) && !signal.intent
    && (!signal.expires_at || new Date(signal.expires_at) > new Date())
}

export function PersistentSignals() {
  const result = useService(useCallback(() => Promise.all([phaseThreeApi.signals(), phaseThreeApi.accounts()]), []))
  const { can } = useAuth()
  const [accountId, setAccountId] = useState('')
  const [working, setWorking] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [created, setCreated] = useState<Record<string, string>>({})
  const identities = useRef<Record<string, string>>({})
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Signals unavailable.'} />
  const [signals, accountPage] = result.data
  const selectedAccount = accountId || accountPage.data[0]?.public_id || ''
  const simulate = async (signal: Signal) => {
    if (working || created[signal.public_id]) return
    const key = identities.current[signal.public_id] ??= makePhaseThreeIdentity('signal-intent')
    setWorking(signal.public_id); setErrors((old) => ({ ...old, [signal.public_id]: '' }))
    try {
      const intent = await phaseThreeApi.createSignalIntent(signal.public_id, {
        account_public_id: selectedAccount, idempotency_key: key,
        requested_volume: n(signal.instrument?.minimum_volume || .01), risk_percent: 1,
      })
      setCreated((old) => ({ ...old, [signal.public_id]: intent.public_id }))
      result.reload()
    } catch (error) {
      setErrors((old) => ({ ...old, [signal.public_id]: firstValidationError(error) }))
    } finally { setWorking('') }
  }
  return <>
    <PageHeader title="AI Signal Center" description="Database-backed simulated signals. Explanations are clearly MOCK; actions create intents only." actions={<StatusBadge tone="purple">MOCK AI EXPLANATION</StatusBadge>} />
    <label className="field signal-account" htmlFor="signal-account"><span>Persisted simulation account</span><select id="signal-account" value={selectedAccount} onChange={(e) => setAccountId(e.target.value)}>{accountPage.data.map((a) => <option value={a.public_id} key={a.public_id}>{a.name}</option>)}</select></label>
    {!signals.data.length ? <EmptyState title="No persistent signals" detail="No database-backed simulated signals are available." /> :
      <div className="signal-grid">{signals.data.map((signal) => <article className="signal-card" key={signal.public_id}>
        <div className="signal-card-head"><div><span className="sim-label">DATABASE SIGNAL · SIMULATION</span><h2>{signal.symbol} <DirectionBadge value={signal.direction} /></h2><p>{signal.strategy?.name ?? 'Unassigned strategy'} · {signal.timeframe ?? '—'} · {signal.market_regime ?? '—'}</p></div><strong>{n(signal.score).toFixed(0)}/100</strong></div>
        <div className="price-levels"><div><span>Entry</span><strong>{value(signal.entry_reference)}</strong></div><div><span>Stop loss</span><strong>{value(signal.stop_loss)}</strong></div><div><span>Take profit 1</span><strong>{value(signal.take_profit_1_reference)}</strong></div><div><span>Status</span><strong>{signal.status}</strong></div></div>
        <div className="mock-explanation"><strong>MOCK explanation</strong><p>{signal.explanation ?? 'No mock explanation stored.'}</p></div>
        {errors[signal.public_id] && <p className="form-alert error" role="alert">{errors[signal.public_id]}</p>}
        {created[signal.public_id] && <p className="form-alert success"><Check /> Intent {created[signal.public_id]} created. Not evaluated or executed.</p>}
        <button className="btn primary" disabled={!can('simulation_lifecycle.create') || !selectedAccount || !signalEligible(signal) || !!working || !!created[signal.public_id]} onClick={() => simulate(signal)}>
          {working === signal.public_id ? 'CREATING…' : 'SIMULATE SIGNAL'}
        </button>
      </article>)}</div>}
  </>
}

function useLifecycleLookup() {
  return useService(useCallback(() => phaseThreeApi.tradeIntents(), []))
}

function intentForOrder(intents: TradeIntent[], order: Order) {
  return intents.find((intent) => intent.execution_command?.order?.id === order.id || intent.execution_command?.order?.public_id === order.public_id)
}
function intentForPosition(intents: TradeIntent[], position: Position) {
  return intents.find((intent) => intent.execution_command?.order?.position?.id === position.id)
}

function OrderDetailDrawer({ publicId, intentId, onLifecycle, onClose }: { publicId: string; intentId?: string; onLifecycle: () => void; onClose: () => void }) {
  const result = useService(useCallback(() => phaseThreeApi.order(publicId), [publicId]))
  return <aside className="drawer lifecycle-drawer" aria-label="Order details"><button className="drawer-close" aria-label="Close order details" onClick={onClose}><X /></button>
    <span className="eyebrow">PERSISTED SIMULATION ORDER</span><h2>{publicId}</h2>
    {result.loading ? <LoadingState /> : result.error || !result.data ? <ErrorState message={result.error ?? 'Order unavailable.'} /> : <>
      <FieldList fields={[
        ['Status', result.data.status], ['Account ID', result.data.broker_account_id], ['Symbol', result.data.symbol], ['Side', result.data.side],
        ['Order type', result.data.order_type], ['Requested / filled / remaining', `${result.data.requested_volume} / ${result.data.filled_volume} / ${result.data.remaining_volume}`],
        ['Requested price', result.data.requested_price], ['Average fill', result.data.average_fill_price], ['SL / TP', `${value(result.data.stop_loss)} / ${value(result.data.take_profit)}`],
        ['Command', result.data.execution_command?.public_id], ['Intent internal ID', result.data.trade_intent_id], ['Requested', date(result.data.requested_at)],
        ['Accepted', date(result.data.accepted_at)], ['Filled', date(result.data.filled_at)], ['Cancelled', date(result.data.cancelled_at)],
      ]} />
      <h3>Deals</h3>{result.data.deals?.length ? result.data.deals.map((deal) => <FieldList key={deal.public_id} fields={[[deal.public_id, `${deal.type} · ${deal.volume} @ ${deal.price} · ${date(deal.executed_at)}`]]} />) : <p>No deals.</p>}
      <h3>Position & events</h3><p>{result.data.position?.public_id ?? 'No position created for this order.'}</p>
      {result.data.position?.events?.map((event) => <p key={event.public_id}>{event.type} · {date(event.occurred_at)} · {event.public_id}</p>)}
      {intentId && <button className="btn ghost lifecycle-link" onClick={onLifecycle}>View lifecycle: {intentId}</button>}
    </>}
  </aside>
}

export function PersistentOrders() {
  const orders = useService(useCallback(() => Promise.all([phaseThreeApi.orders(), phaseTwoApi.brokerAccounts(), phaseTwoApi.strategies()]), []))
  const intents = useLifecycleLookup()
  const { can } = useAuth()
  const [selected, setSelected] = useState('')
  const [lifecycle, setLifecycle] = useState('')
  const [confirm, setConfirm] = useState<Order>()
  const [busy, setBusy] = useState('')
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const keys = useRef<Record<string, string>>({})
  if (orders.loading || intents.loading) return <LoadingState />
  if (orders.error || !orders.data) return <ErrorState message={orders.error ?? 'Orders unavailable.'} />
  const [page, accountPage, strategies] = orders.data
  const accountName = (id: number | null) => accountPage.data.find((a) => a.id === id)?.name ?? `Account #${id ?? '—'}`
  const strategyName = (id: number | null) => strategies.data.find((s) => s.id === id)?.name ?? (id ? `Strategy #${id}` : 'Manual')
  const cancel = async () => {
    if (!confirm || busy) return
    setBusy(confirm.public_id); setError('')
    try {
      const key = keys.current[confirm.public_id] ??= makePhaseThreeIdentity('cancel')
      const command = await phaseThreeApi.cancelOrder(confirm.public_id, key)
      setNotice(`${confirm.public_id} cancelled by ${command.public_id}.`)
      orders.reload(); intents.reload(); delete keys.current[confirm.public_id]
    } catch (caught) { setError(firstValidationError(caught)) }
    finally { setBusy(''); setConfirm(undefined) }
  }
  const selectedOrder = page.data.find((item) => item.public_id === selected)
  const selectedIntent = selectedOrder ? intentForOrder(intents.data?.data ?? [], selectedOrder) : undefined
  return <>
    <PageHeader title="Pending / Orders" description="Actual persisted Phase 3 orders. Cancellation is limited to accepted pending simulation orders." actions={<EnvironmentBadge />} />
    {notice && <div className="success-banner"><Check />{notice}</div>}{error && <div className="danger-banner" role="alert">{error}</div>}
    <Panel title="Persistent order ledger" subtitle={`${page.total} orders`}>
      <DataTable columns={['Public ID', 'Account', 'Symbol', 'Side', 'Type', 'Requested', 'Filled', 'Remaining', 'Price', 'SL', 'TP', 'Status', 'Strategy', 'Created', 'Actions']}
        rows={page.data.map((order) => [
          <button className="link" onClick={() => setSelected(order.public_id)}>{order.public_id}</button>, accountName(order.broker_account_id), order.symbol,
          <DirectionBadge value={order.side} />, order.order_type, order.requested_volume, order.filled_volume, order.remaining_volume,
          value(order.requested_price), value(order.stop_loss), value(order.take_profit), <StatusBadge tone={pendingOrder(order) ? 'warning' : order.status === 'FILLED' ? 'good' : 'neutral'}>{order.status}</StatusBadge>,
          strategyName(order.trading_strategy_id), date(order.created_at), <div className="row-actions"><button onClick={() => setSelected(order.public_id)}>View</button>
            {pendingOrder(order) && can('simulation_orders.cancel') && <button disabled={!!busy} onClick={() => setConfirm(order)}>SIMULATE CANCEL</button>}</div>,
        ])} />
    </Panel>
    <ConfirmationDialog open={!!confirm} title="Confirm SIMULATE CANCEL" onCancel={() => setConfirm(undefined)} onConfirm={cancel}>Cancel {confirm?.public_id} in the simulation ledger only?</ConfirmationDialog>
    {selected && <OrderDetailDrawer publicId={selected} intentId={selectedIntent?.public_id} onLifecycle={() => selectedIntent && setLifecycle(selectedIntent.public_id)} onClose={() => setSelected('')} />}
    {lifecycle && <TradeLifecycleDrawer intentPublicId={lifecycle} onClose={() => setLifecycle('')} />}
  </>
}

type PositionAction = { type: 'close' | 'partial' | 'sl' | 'tp'; position: Position }

function PositionDetailDrawer({ publicId, onClose }: { publicId: string; onClose: () => void }) {
  const result = useService(useCallback(() => phaseThreeApi.position(publicId), [publicId]))
  return <aside className="drawer lifecycle-drawer" aria-label="Position details"><button className="drawer-close" aria-label="Close position details" onClick={onClose}><X /></button>
    <span className="eyebrow">PERSISTED SIMULATION POSITION</span><h2>{publicId}</h2>
    {result.loading ? <LoadingState /> : result.error || !result.data ? <ErrorState message={result.error ?? 'Position unavailable.'} /> : <>
      <FieldList fields={[
        ['Status', result.data.status], ['Account', result.data.broker_account?.name], ['Symbol', result.data.symbol], ['Side', result.data.side],
        ['Initial / current volume', `${result.data.initial_volume} / ${result.data.current_volume}`], ['Average entry', result.data.average_entry_price],
        ['Current MOCK price', result.data.current_price], ['SL / TP', `${value(result.data.stop_loss)} / ${value(result.data.take_profit)}`],
        ['Unrealized P/L', result.data.unrealized_pnl], ['Realized P/L', result.data.realized_pnl], ['Margin used', result.data.margin_used],
        ['Opening order', result.data.opening_order?.public_id], ['Opened', date(result.data.opened_at)], ['Closed', date(result.data.closed_at)],
      ]} />
      <h3>Deals</h3>{result.data.deals?.map((deal) => <p key={deal.public_id}>{deal.public_id} · {deal.type} · {deal.volume} @ {deal.price}</p>)}
      <h3>Position timeline</h3>{result.data.events?.map((event) => <article className="position-event" key={event.public_id}><strong>{event.type}</strong><span>{event.public_id} · {date(event.occurred_at)}</span><small>Command {event.execution_command?.public_id ?? '—'} · {value(event.volume_before)} → {value(event.volume_after)}</small></article>)}
    </>}
  </aside>
}

export function PersistentPositions() {
  const result = useService(useCallback(() => Promise.all([phaseThreeApi.positions(), phaseTwoApi.strategies()]), []))
  const intents = useLifecycleLookup()
  const { can } = useAuth()
  const [selected, setSelected] = useState('')
  const [lifecycle, setLifecycle] = useState('')
  const [action, setAction] = useState<PositionAction>()
  const [amount, setAmount] = useState('')
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const keys = useRef<Record<string, string>>({})
  if (result.loading || intents.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Positions unavailable.'} />
  const [page, strategies] = result.data
  const openPositions = page.data.filter((position) => position.status !== 'CLOSED')
  const strategyName = (id: number | null) => strategies.data.find((s) => s.id === id)?.name ?? (id ? `Strategy #${id}` : 'Manual')
  const runAction = async () => {
    if (!action || busy) return
    setBusy(true); setError('')
    const scope = `${action.type}:${action.position.public_id}`
    const key = keys.current[scope] ??= makePhaseThreeIdentity(action.type)
    try {
      let command: ExecutionCommand
      if (action.type === 'close') command = await phaseThreeApi.closePosition(action.position.public_id, key)
      else if (action.type === 'partial') command = await phaseThreeApi.partialClosePosition(action.position.public_id, Number(amount), key)
      else if (action.type === 'sl') command = await phaseThreeApi.modifyStopLoss(action.position.public_id, amount ? Number(amount) : null, key)
      else command = await phaseThreeApi.modifyTakeProfit(action.position.public_id, amount ? Number(amount) : null, key)
      setNotice(`${action.type.toUpperCase()} completed in SIMULATION via ${command.public_id}.`)
      delete keys.current[scope]; result.reload(); intents.reload()
    } catch (caught) { setError(firstValidationError(caught)) }
    finally { setBusy(false); setAction(undefined); setAmount('') }
  }
  return <>
    <PageHeader title="Open Positions" description="Persistent Phase 3 simulation positions with backend-stored MOCK prices and safe controls." actions={<EnvironmentBadge />} />
    {notice && <div className="success-banner"><Check />{notice}</div>}{error && <div className="danger-banner" role="alert">{error}</div>}
    <Panel title="Persistent positions" subtitle={`${openPositions.length} open or partially closed positions`}>
      <DataTable columns={['Public ID', 'Account', 'Symbol', 'Side', 'Volume', 'Average entry', 'Current MOCK', 'SL', 'TP', 'Unrealized P/L', 'Strategy', 'Opened', 'Status', 'Actions']}
        rows={openPositions.map((position) => {
          const linkedIntent = intentForPosition(intents.data?.data ?? [], position)
          return [
            <button className="link" onClick={() => setSelected(position.public_id)}>{position.public_id}</button>,
            position.broker_account?.name ?? `Account #${position.broker_account_id}`, position.symbol, <DirectionBadge value={position.side} />,
            position.current_volume, value(position.average_entry_price), value(position.current_price), value(position.stop_loss), value(position.take_profit),
            <PnLDisplay value={n(position.unrealized_pnl)} />, strategyName(position.trading_strategy_id), date(position.opened_at),
            <StatusBadge tone={position.status === 'CLOSED' ? 'neutral' : 'good'}>{position.status}</StatusBadge>,
            <div className="row-actions"><button onClick={() => setSelected(position.public_id)}>View</button>
              {linkedIntent && <button onClick={() => setLifecycle(linkedIntent.public_id)}>Lifecycle</button>}
              {position.status !== 'CLOSED' && can('simulation_positions.manage') && <>
                <button onClick={() => setAction({ type: 'close', position })}>SIMULATE CLOSE</button>
                <button onClick={() => setAction({ type: 'partial', position })}>Partial</button>
                <button onClick={() => setAction({ type: 'sl', position })}>SL</button><button onClick={() => setAction({ type: 'tp', position })}>TP</button>
              </>}</div>,
          ]
        })} />
    </Panel>
    {action && <div className="dialog-backdrop"><div className="dialog" role="dialog" aria-modal="true" aria-labelledby="position-action-title">
      <h2 id="position-action-title">Confirm SIMULATION {action.type.toUpperCase()}</h2><p>This modifies {action.position.public_id} in the simulation ledger only. No broker transmission.</p>
      {action.type !== 'close' && <label className="field" htmlFor="position-action-value"><span>{action.type === 'partial' ? 'Close volume' : action.type === 'sl' ? 'Stop loss' : 'Take profit'}</span><input id="position-action-value" type="number" step="any" value={amount} onChange={(e) => setAmount(e.target.value)} /></label>}
      <div><button className="btn ghost" onClick={() => setAction(undefined)}>Cancel</button><button className="btn danger" disabled={busy || (action.type !== 'close' && !amount)} onClick={runAction}>{busy ? 'WORKING…' : 'Confirm simulation action'}</button></div>
    </div></div>}
    {selected && <PositionDetailDrawer publicId={selected} onClose={() => setSelected('')} />}
    {lifecycle && <TradeLifecycleDrawer intentPublicId={lifecycle} onClose={() => setLifecycle('')} />}
  </>
}

export function LifecycleDistinctions({ intent, decision, command }: { intent: TradeIntent; decision?: RiskDecision | null; command?: ExecutionCommand | null }) {
  return <div aria-label="Lifecycle distinctions"><span>TRADE INTENT: {intent.status}</span><span>RISK DECISION: {decision?.status ?? 'NOT CREATED'}</span><span>EXECUTION COMMAND: {command?.status ?? 'NOT CREATED'}</span></div>
}
