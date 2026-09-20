import { apiRequest } from './client'
import type {
  AppSetting, AuditRecord, BrokerAccountRecord, CurrentUser, DashboardSummary, NotificationRecord,
  Paginated, Preference, RiskProfileRecord, SimulationOrderInput, SimulationOrderRecord, StrategyRecord,
  SystemStatus, UserRecord, TradingInstrument, Signal, TradeIntent, CreateTradeIntentInput,
  CreateSignalIntentInput, ExecutionCommand, Order, Position, ServiceHeartbeat,
  Mt5BridgeStatus, Mt5BridgeConnection, Mt5BridgeEnvelope, Mt5ExternalPosition, Mt5ReconciliationRun,
  MarketSnapshot, MarketQuote, MarketCandleBar, MarketSymbolInfo, MarketExtensionHooks,
  IndicatorCatalogItem, IndicatorResult,
  StrategyPluginCatalogItem, StrategyScanResult, ScannerBoard, SignalCandidate,
  RiskEngineDashboard, RiskDecision, RiskLockRecord, ProposedPlan,
} from './types'

export const authApi = {
  me: () => apiRequest<CurrentUser>('/api/v1/auth/me', { csrf: false }),
  login: (email: string, password: string, remember = false) =>
    apiRequest<CurrentUser>('/api/v1/auth/login', { method: 'POST', body: { email, password, remember } }),
  logout: () => apiRequest<{ message: string }>('/api/v1/auth/logout', { method: 'POST' }),
  requestPasswordReset: (email: string) =>
    apiRequest<{ message: string }>('/api/v1/auth/password/request', { method: 'POST', body: { email } }),
}

export const phaseTwoApi = {
  dashboard: () => apiRequest<DashboardSummary>('/api/v1/dashboard'),
  status: () => apiRequest<SystemStatus>('/api/v1/system/status', { csrf: false }),
  strategies: () => apiRequest<Paginated<StrategyRecord>>('/api/v1/strategies'),
  riskProfiles: () => apiRequest<Paginated<RiskProfileRecord>>('/api/v1/risk-profiles'),
  brokerAccounts: () => apiRequest<Paginated<BrokerAccountRecord>>('/api/v1/broker-accounts'),
  notifications: () => apiRequest<Paginated<NotificationRecord>>('/api/v1/notifications'),
  readNotification: (id: string) => apiRequest<NotificationRecord>(`/api/v1/notifications/${id}/read`, { method: 'POST' }),
  auditLogs: () => apiRequest<Paginated<AuditRecord>>('/api/v1/audit-logs'),
  settings: () => apiRequest<AppSetting[]>('/api/v1/settings'),
  updateSetting: (key: string, value: unknown, isPublic = false) =>
    apiRequest<AppSetting>(`/api/v1/settings/${encodeURIComponent(key)}`, { method: 'PUT', body: { value, is_public: isPublic } }),
  emergencyStop: (enabled: boolean) =>
    apiRequest<AppSetting>('/api/v1/emergency-stop', { method: 'PUT', body: { enabled } }),
  preference: () => apiRequest<Preference>('/api/v1/preferences'),
  updatePreference: (values: Partial<Preference>) =>
    apiRequest<Preference>('/api/v1/preferences', { method: 'PUT', body: values }),
  submitSimulationOrder: (input: SimulationOrderInput) =>
    apiRequest<SimulationOrderRecord>('/api/v1/simulation/orders', { method: 'POST', body: input }),
  users: () => apiRequest<Paginated<UserRecord>>('/api/v1/users'),
  createUser: (input: { name: string; email: string; password: string; role: string }) =>
    apiRequest<UserRecord>('/api/v1/users', { method: 'POST', body: input }),
  updateUser: (id: number, input: { name: string; email: string; password?: string; role: string }) =>
    apiRequest<UserRecord>(`/api/v1/users/${id}`, { method: 'PUT', body: input }),
  setUserStatus: (id: number, status: 'activate' | 'suspend' | 'disable') =>
    apiRequest<UserRecord>(`/api/v1/users/${id}/${status}`, { method: 'POST' }),
}

export function makeOrderIdentity() {
  const commandId = crypto.randomUUID()
  return { command_id: commandId, idempotency_key: `web:${commandId}` }
}

export const phaseThreeApi = {
  instruments: (symbol?: string) =>
    apiRequest<Paginated<TradingInstrument>>(`/api/v1/instruments${symbol ? `?symbol=${encodeURIComponent(symbol)}` : ''}`),
  instrument: (publicId: string) => apiRequest<TradingInstrument>(`/api/v1/instruments/${encodeURIComponent(publicId)}`),
  signals: () => apiRequest<Paginated<Signal>>('/api/v1/signals'),
  signal: (publicId: string) => apiRequest<Signal>(`/api/v1/signals/${encodeURIComponent(publicId)}`),
  createSignalIntent: (publicId: string, input: CreateSignalIntentInput) =>
    apiRequest<TradeIntent>(`/api/v1/signals/${encodeURIComponent(publicId)}/trade-intent`, { method: 'POST', body: input }),
  tradeIntents: () => apiRequest<Paginated<TradeIntent>>('/api/v1/trade-intents'),
  createTradeIntent: (input: CreateTradeIntentInput) =>
    apiRequest<TradeIntent>('/api/v1/trade-intents', { method: 'POST', body: input }),
  tradeIntent: (publicId: string) => apiRequest<TradeIntent>(`/api/v1/trade-intents/${encodeURIComponent(publicId)}`),
  evaluateTradeIntent: (publicId: string) =>
    apiRequest<TradeIntent>(`/api/v1/trade-intents/${encodeURIComponent(publicId)}/evaluate`, { method: 'POST' }),
  executeTradeIntent: (publicId: string, idempotencyKey: string) =>
    apiRequest<ExecutionCommand>(`/api/v1/trade-intents/${encodeURIComponent(publicId)}/execute`, {
      method: 'POST', body: { idempotency_key: idempotencyKey },
    }),
  orders: (status?: string) =>
    apiRequest<Paginated<Order>>(`/api/v1/orders${status ? `?status=${encodeURIComponent(status)}` : ''}`),
  order: (publicId: string) => apiRequest<Order>(`/api/v1/orders/${encodeURIComponent(publicId)}`),
  cancelOrder: (publicId: string, idempotencyKey: string) =>
    apiRequest<ExecutionCommand>(`/api/v1/orders/${encodeURIComponent(publicId)}/cancel`, {
      method: 'POST', body: { idempotency_key: idempotencyKey },
    }),
  positions: (status?: string) =>
    apiRequest<Paginated<Position>>(`/api/v1/positions${status ? `?status=${encodeURIComponent(status)}` : ''}`),
  position: (publicId: string) => apiRequest<Position>(`/api/v1/positions/${encodeURIComponent(publicId)}`),
  closePosition: (publicId: string, idempotencyKey: string, volume?: number) =>
    apiRequest<ExecutionCommand>(`/api/v1/positions/${encodeURIComponent(publicId)}/close`, {
      method: 'POST', body: { idempotency_key: idempotencyKey, ...(volume === undefined ? {} : { volume }) },
    }),
  partialClosePosition: (publicId: string, volume: number, idempotencyKey: string) =>
    apiRequest<ExecutionCommand>(`/api/v1/positions/${encodeURIComponent(publicId)}/partial-close`, {
      method: 'POST', body: { volume, idempotency_key: idempotencyKey },
    }),
  modifyStopLoss: (publicId: string, stopLoss: number | null, idempotencyKey: string) =>
    apiRequest<ExecutionCommand>(`/api/v1/positions/${encodeURIComponent(publicId)}/stop-loss`, {
      method: 'PUT', body: { stop_loss: stopLoss, idempotency_key: idempotencyKey },
    }),
  modifyTakeProfit: (publicId: string, takeProfit: number | null, idempotencyKey: string) =>
    apiRequest<ExecutionCommand>(`/api/v1/positions/${encodeURIComponent(publicId)}/take-profit`, {
      method: 'PUT', body: { take_profit: takeProfit, idempotency_key: idempotencyKey },
    }),
  heartbeats: (service?: string) =>
    apiRequest<Paginated<ServiceHeartbeat>>(`/api/v1/heartbeats${service ? `?service=${encodeURIComponent(service)}` : ''}`),
  status: phaseTwoApi.status,
  accounts: phaseTwoApi.brokerAccounts,
}

export function makePhaseThreeIdentity(scope: string) {
  return `${scope}:web:${crypto.randomUUID()}`
}

export const phaseSevenApi = {
  catalog: () => apiRequest<{ phase: number; plugins: StrategyPluginCatalogItem[]; upload_allowed: false; execution: { order_send: false } }>('/api/v1/strategy-engine/catalog'),
  health: () => apiRequest<Record<string, unknown>>('/api/v1/strategy-engine/health'),
  scan: (symbol: string, timeframe = 'M5', prefer = 'simulation') =>
    apiRequest<StrategyScanResult>('/api/v1/strategy-engine/scan', { method: 'POST', body: { symbol, timeframe, prefer } }),
  confluence: (symbol: string, timeframe = 'M5', prefer = 'simulation') =>
    apiRequest<Record<string, unknown>>('/api/v1/strategy-engine/confluence', { method: 'POST', body: { symbol, timeframe, prefer } }),
  matrix: (prefer = 'simulation') =>
    apiRequest<{ phase: number; matrix: Array<Record<string, unknown>>; execution: { order_send: false } }>(`/api/v1/strategy-engine/matrix?prefer=${encodeURIComponent(prefer)}`),
  run: (prefer = 'simulation') =>
    apiRequest<Record<string, unknown>>('/api/v1/strategy-engine/run', { method: 'POST', body: { prefer } }),
  evaluate: (strategyId: number, body: { symbol?: string; timeframe?: string; prefer?: string; create_signal?: boolean } = {}) =>
    apiRequest<Record<string, unknown>>(`/api/v1/strategies/${strategyId}/evaluate`, { method: 'POST', body }),
  performance: (strategyId: number) =>
    apiRequest<Record<string, unknown>>(`/api/v1/strategies/${strategyId}/performance`),
  enable: (strategyId: number) =>
    apiRequest<StrategyRecord>(`/api/v1/strategies/${strategyId}/enable`, { method: 'POST' }),
  disable: (strategyId: number) =>
    apiRequest<StrategyRecord>(`/api/v1/strategies/${strategyId}/disable`, { method: 'POST' }),
  strategy: (strategyId: number) =>
    apiRequest<StrategyRecord & { plugin?: StrategyPluginCatalogItem | null }>(`/api/v1/strategies/${strategyId}`),
  signals: phaseThreeApi.signals,
  signal: phaseThreeApi.signal,
}

export const phaseEightApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/scanner/health'),
  universe: () => apiRequest<Record<string, unknown>>('/api/v1/scanner/universe'),
  board: (params?: { status?: string; symbol?: string; timeframe?: string; direction?: string; limit?: number }) => {
    const query = new URLSearchParams()
    if (params?.status) query.set('status', params.status)
    if (params?.symbol) query.set('symbol', params.symbol)
    if (params?.timeframe) query.set('timeframe', params.timeframe)
    if (params?.direction) query.set('direction', params.direction)
    if (params?.limit !== undefined) query.set('limit', String(params.limit))
    const suffix = query.toString() ? `?${query}` : ''
    return apiRequest<ScannerBoard>(`/api/v1/scanner/board${suffix}`)
  },
  run: (body: {
    trigger?: 'MANUAL' | 'ON_INTERVAL' | 'ON_CANDLE_CLOSE'
    symbols?: string[]
    timeframes?: string[]
    prefer?: string
    create_signals?: boolean
    create_candidates?: boolean
  } = {}) => apiRequest<Record<string, unknown>>('/api/v1/scanner/run', { method: 'POST', body }),
  matrix: (prefer = 'simulation', symbols?: string[], timeframes?: string[]) => {
    const query = new URLSearchParams({ prefer })
    if (symbols?.length) query.set('symbols', symbols.join(','))
    if (timeframes?.length) query.set('timeframes', timeframes.join(','))
    return apiRequest<{ phase: number; matrix: Array<Record<string, unknown>>; execution: { order_send: false } }>(`/api/v1/scanner/matrix?${query}`)
  },
  queue: (params?: { status?: string; symbol?: string; limit?: number }) => {
    const query = new URLSearchParams()
    if (params?.status) query.set('status', params.status)
    if (params?.symbol) query.set('symbol', params.symbol)
    if (params?.limit !== undefined) query.set('limit', String(params.limit))
    const suffix = query.toString() ? `?${query}` : ''
    return apiRequest<{ phase: number; count: number; candidates: SignalCandidate[]; disclaimer: string; execution: { order_send: false } }>(`/api/v1/scanner/queue${suffix}`)
  },
  dismiss: (publicId: string) =>
    apiRequest<SignalCandidate>(`/api/v1/scanner/candidates/${encodeURIComponent(publicId)}/dismiss`, { method: 'POST' }),
  invalidate: (publicId: string, reason = 'MANUAL') =>
    apiRequest<SignalCandidate>(`/api/v1/scanner/candidates/${encodeURIComponent(publicId)}/invalidate`, { method: 'POST', body: { reason } }),
  markSimulate: (publicId: string) =>
    apiRequest<{ candidate: SignalCandidate; simulate_target: string; mt5_execution: false; execution: { order_send: false } }>(
      `/api/v1/scanner/candidates/${encodeURIComponent(publicId)}/mark-simulate`,
      { method: 'POST' },
    ),
  alerts: () => apiRequest<{ phase: number; alerts: Array<Record<string, unknown>>; pipeline: Record<string, unknown> }>('/api/v1/scanner/alerts'),
  configs: () => apiRequest<{ phase: number; configs: Array<Record<string, unknown>> }>('/api/v1/scanner/configs'),
  upsertConfig: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/scanner/configs', { method: 'PUT', body }),
}

export const phaseNineApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/risk-engine/health'),
  catalog: () => apiRequest<{ phase: number; rules: Array<Record<string, unknown>>; execution: { order_send: false } }>('/api/v1/risk-engine/catalog'),
  dashboard: () => apiRequest<RiskEngineDashboard>('/api/v1/risk-engine/dashboard'),
  decisions: () => apiRequest<Paginated<RiskDecision>>('/api/v1/risk-engine/decisions'),
  decision: (publicId: string) => apiRequest<RiskDecision>(`/api/v1/risk-engine/decisions/${encodeURIComponent(publicId)}`),
  plan: (publicId: string) => apiRequest<ProposedPlan>(`/api/v1/risk-engine/plans/${encodeURIComponent(publicId)}`),
  locks: () => apiRequest<Paginated<RiskLockRecord>>('/api/v1/risk-engine/locks'),
  createLock: (body: { lock_type: string; reason_code: string; message: string; account_public_id?: string }) =>
    apiRequest<RiskLockRecord>('/api/v1/risk-engine/locks', { method: 'POST', body }),
  releaseLock: (publicId: string, note = 'Released from UI') =>
    apiRequest<RiskLockRecord>(`/api/v1/risk-engine/locks/${encodeURIComponent(publicId)}/release`, { method: 'POST', body: { note } }),
}

export const mt5Api = {
  status: () => apiRequest<Mt5BridgeStatus>('/api/v1/mt5/status'),
  health: () => apiRequest<Mt5BridgeEnvelope<Record<string, unknown>>>('/api/v1/mt5/bridge/health'),
  account: () => apiRequest<Mt5BridgeEnvelope<Record<string, unknown>>>('/api/v1/mt5/bridge/account'),
  symbols: () => apiRequest<Mt5BridgeEnvelope<Array<Record<string, unknown>>>>('/api/v1/mt5/bridge/symbols'),
  quote: (symbol: string) => apiRequest<Mt5BridgeEnvelope<Record<string, unknown>>>(`/api/v1/mt5/bridge/quotes/${encodeURIComponent(symbol)}`),
  candles: (symbol: string, timeframe = 'M5', count = 100) =>
    apiRequest<Mt5BridgeEnvelope<Array<Record<string, unknown>>>>(
      `/api/v1/mt5/bridge/candles/${encodeURIComponent(symbol)}?timeframe=${timeframe}&count=${count}`,
    ),
  positions: () => apiRequest<Mt5BridgeEnvelope<Array<Record<string, unknown>>>>('/api/v1/mt5/bridge/positions'),
  connections: () => apiRequest<Paginated<Mt5BridgeConnection>>('/api/v1/mt5/connections'),
  createConnection: (name: string, isEnabled = false) =>
    apiRequest<Mt5BridgeConnection>('/api/v1/mt5/connections', { method: 'POST', body: { name, is_enabled: isEnabled } }),
  testConnection: (id: number) =>
    apiRequest<{ connection: Mt5BridgeConnection; bridge: Mt5BridgeEnvelope<Record<string, unknown>> }>(
      `/api/v1/mt5/connections/${id}/test`, { method: 'POST' },
    ),
  setConnectionEnabled: (id: number, isEnabled: boolean) =>
    apiRequest<Mt5BridgeConnection>(`/api/v1/mt5/connections/${id}/enable`, {
      method: 'POST',
      body: { is_enabled: isEnabled },
    }),
  deleteConnection: (id: number) =>
    apiRequest<{ deleted: boolean; id: number | null }>(`/api/v1/mt5/connections/${id}`, { method: 'DELETE' }),
  syncConnection: (id: number) =>
    apiRequest<Record<string, unknown>>(`/api/v1/mt5/connections/${id}/sync`, { method: 'POST' }),
  mappingPositions: (mappingId: number) =>
    apiRequest<Paginated<Mt5ExternalPosition>>(`/api/v1/mt5/mappings/${mappingId}/positions`),
  reconcileMapping: (mappingId: number) =>
    apiRequest<Mt5ReconciliationRun>(`/api/v1/mt5/mappings/${mappingId}/reconcile`, { method: 'POST' }),
  reconciliationRuns: () => apiRequest<Paginated<Mt5ReconciliationRun>>('/api/v1/mt5/reconciliation-runs'),
}

export const marketApi = {
  snapshot: (params?: {
    symbols?: string
    candle_symbol?: string
    timeframe?: string
    candle_count?: number
    prefer?: 'auto' | 'bridge' | 'simulation'
    persist?: boolean
  }) => {
    const query = new URLSearchParams()
    if (params?.symbols) query.set('symbols', params.symbols)
    if (params?.candle_symbol) query.set('candle_symbol', params.candle_symbol)
    if (params?.timeframe) query.set('timeframe', params.timeframe)
    if (params?.candle_count !== undefined) query.set('candle_count', String(params.candle_count))
    if (params?.prefer) query.set('prefer', params.prefer)
    if (params?.persist !== undefined) query.set('persist', params.persist ? '1' : '0')
    const suffix = query.toString() ? `?${query}` : ''
    return apiRequest<MarketSnapshot>(`/api/v1/market/snapshot${suffix}`)
  },
  quotes: (symbols?: string, prefer: 'auto' | 'bridge' | 'simulation' = 'auto') => {
    const query = new URLSearchParams({ prefer })
    if (symbols) query.set('symbols', symbols)
    return apiRequest<MarketQuote[]>(`/api/v1/market/quotes?${query}`)
  },
  quote: (symbol: string, prefer: 'auto' | 'bridge' | 'simulation' = 'auto') =>
    apiRequest<MarketQuote>(`/api/v1/market/quotes/${encodeURIComponent(symbol)}?prefer=${prefer}`),
  candles: (symbol: string, timeframe = 'M5', count = 100, prefer: 'auto' | 'bridge' | 'simulation' = 'auto', closedOnly = false) =>
    apiRequest<MarketCandleBar[]>(
      `/api/v1/market/candles/${encodeURIComponent(symbol)}?timeframe=${timeframe}&count=${count}&prefer=${prefer}&closed_only=${closedOnly ? '1' : '0'}`,
    ),
  closedCandles: (symbol: string, timeframe = 'M5', count = 100, prefer: 'auto' | 'bridge' | 'simulation' = 'auto') =>
    apiRequest<MarketCandleBar[]>(
      `/api/v1/market/candles/${encodeURIComponent(symbol)}/closed?timeframe=${timeframe}&count=${count}&prefer=${prefer}`,
    ),
  symbols: (prefer: 'auto' | 'bridge' | 'simulation' = 'auto') =>
    apiRequest<MarketSymbolInfo[]>(`/api/v1/market/symbols?prefer=${prefer}`),
  sessions: () => apiRequest<Record<string, unknown>>('/api/v1/market/sessions'),
  health: () => apiRequest<Record<string, unknown>>('/api/v1/market/health'),
  qualityCheck: (prefer: 'auto' | 'bridge' | 'simulation' = 'auto') =>
    apiRequest<Record<string, unknown>>('/api/v1/market/quality-check', { method: 'POST', body: { prefer } }),
  syncSymbols: (prefer: 'auto' | 'bridge' | 'simulation' = 'auto') =>
    apiRequest<Record<string, unknown>>('/api/v1/market/symbols/sync', { method: 'POST', body: { prefer } }),
  backfill: (symbol: string, timeframe: string, count = 100, prefer: 'auto' | 'bridge' | 'simulation' = 'auto') =>
    apiRequest<Record<string, unknown>>('/api/v1/market/backfill', {
      method: 'POST',
      body: { symbol, timeframe, count, prefer },
    }),
  monitored: () => apiRequest<{ symbols: string[] }>('/api/v1/market/monitored'),
  setMonitored: (symbols: string[]) =>
    apiRequest<{ symbols: string[] }>('/api/v1/market/monitored', { method: 'PUT', body: { symbols } }),
  extensionHooks: () => apiRequest<MarketExtensionHooks>('/api/v1/market/extension-hooks'),
}

export const indicatorApi = {
  catalog: () => apiRequest<{
    phase: number
    engine: string
    read_only: boolean
    indicators: IndicatorCatalogItem[]
    execution: { order_send: boolean }
  }>('/api/v1/indicators/catalog'),
  health: () => apiRequest<Record<string, unknown>>('/api/v1/indicators/health'),
  compute: (body: {
    indicator: string
    symbol: string
    timeframe?: string
    count?: number
    prefer?: 'auto' | 'bridge' | 'simulation'
    params?: Record<string, string | number>
    cache?: boolean
  }) => apiRequest<IndicatorResult>('/api/v1/indicators/compute', { method: 'POST', body }),
  series: (
    indicator: string,
    params: {
      symbol: string
      timeframe?: string
      count?: number
      prefer?: 'auto' | 'bridge' | 'simulation'
      period?: number
      fast?: number
      slow?: number
      signal?: number
      std_dev?: number
      source?: string
    },
  ) => {
    const query = new URLSearchParams()
    query.set('symbol', params.symbol)
    if (params.timeframe) query.set('timeframe', params.timeframe)
    if (params.count !== undefined) query.set('count', String(params.count))
    if (params.prefer) query.set('prefer', params.prefer)
    if (params.period !== undefined) query.set('period', String(params.period))
    if (params.fast !== undefined) query.set('fast', String(params.fast))
    if (params.slow !== undefined) query.set('slow', String(params.slow))
    if (params.signal !== undefined) query.set('signal', String(params.signal))
    if (params.std_dev !== undefined) query.set('std_dev', String(params.std_dev))
    if (params.source) query.set('source', params.source)
    return apiRequest<IndicatorResult>(
      `/api/v1/indicators/${encodeURIComponent(indicator)}/series?${query}`,
    )
  },
  batch: (body: {
    indicators: string[]
    symbol: string
    timeframe?: string
    count?: number
    prefer?: 'auto' | 'bridge' | 'simulation'
    params?: Record<string, Record<string, string | number>>
  }) => apiRequest<{ symbol: string; timeframe: string; results: IndicatorResult[]; read_only: boolean }>(
    '/api/v1/indicators/batch',
    { method: 'POST', body },
  ),
}


export const phaseTenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/execution/health'),
  dashboard: () => apiRequest<{
    phase: number
    disclaimer: string
    allow_demo_execution: boolean
    auto_demo_execution: boolean
    allow_live_execution: boolean
    commands: Array<Record<string, unknown>>
    confirmations: Array<Record<string, unknown>>
    results: Array<Record<string, unknown>>
    events: Array<Record<string, unknown>>
    unknown_count: number
  }>('/api/v1/execution/dashboard'),
  metrics: () => apiRequest<Record<string, unknown>>('/api/v1/execution/metrics'),
  startConfirmation: (tradeIntentPublicId: string, idempotency_key: string) =>
    apiRequest<{ confirmation: { public_id: string; preview_payload?: Record<string, unknown> }; challenge_token: string; replayed: boolean; disclaimer: string }>(
      `/api/v1/execution/intents/${encodeURIComponent(tradeIntentPublicId)}/confirmations`,
      { method: 'POST', body: { idempotency_key } },
    ),
  completeConfirmation: (confirmationPublicId: string, challenge_token: string) =>
    apiRequest<{ confirmation: { public_id: string }; confirm_token: string; disclaimer: string }>(
      `/api/v1/execution/confirmations/${encodeURIComponent(confirmationPublicId)}/step2`,
      { method: 'POST', body: { challenge_token } },
    ),
  submit: (body: {
    trade_intent_public_id: string
    confirmation_public_id: string
    confirm_token: string
    idempotency_key: string
  }) => apiRequest<{ command: { public_id: string; status: string; submission_state?: string }; result: Record<string, unknown> | null; replayed: boolean; environment: string }>(
    '/api/v1/execution/demo/submit',
    { method: 'POST', body },
  ),
  recover: (commandPublicId: string) =>
    apiRequest<{ command: Record<string, unknown>; blind_retry: false }>(
      `/api/v1/execution/commands/${encodeURIComponent(commandPublicId)}/recover`,
      { method: 'POST' },
    ),
  reconcile: (broker_account_id?: number) =>
    apiRequest<Record<string, unknown>>('/api/v1/execution/reconcile', {
      method: 'POST',
      body: broker_account_id ? { broker_account_id } : {},
    }),
}


export const phaseElevenApi = {
  status: () => apiRequest<Record<string, unknown>>('/api/v1/trade-management/status'),
  health: () => apiRequest<Record<string, unknown>>('/api/v1/trade-management/health'),
  dashboard: () => apiRequest<{
    cards: Record<string, unknown>
    positions: Array<Record<string, unknown>>
    recent_decisions: Array<Record<string, unknown>>
  }>('/api/v1/trade-management/dashboard'),
  positions: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/positions-managed'),
  show: (id: string) => apiRequest<{
    position: Record<string, unknown>
    events: Array<Record<string, unknown>>
    decisions: Array<Record<string, unknown>>
    why?: string
    chart_markers: Array<Record<string, unknown>>
  }>(`/api/v1/positions-managed/${encodeURIComponent(id)}`),
  pause: (id: string) => apiRequest<Record<string, unknown>>(`/api/v1/positions-managed/${encodeURIComponent(id)}/pause`, { method: 'POST' }),
  resume: (id: string) => apiRequest<Record<string, unknown>>(`/api/v1/positions-managed/${encodeURIComponent(id)}/resume`, { method: 'POST' }),
  evaluate: (id: string, body: Record<string, unknown> = {}) =>
    apiRequest<{ decision: Record<string, unknown>; action: Record<string, unknown> | null }>(
      `/api/v1/positions-managed/${encodeURIComponent(id)}/evaluate`,
      { method: 'POST', body },
    ),
  prepareClose: (id: string, idempotency_key: string) =>
    apiRequest<{ confirmation: { public_id: string }; challenge_token: string }>(
      `/api/v1/positions-managed/${encodeURIComponent(id)}/close/prepare`,
      { method: 'POST', body: { idempotency_key } },
    ),
  confirmClose: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(
      `/api/v1/positions-managed/${encodeURIComponent(id)}/close/confirm`,
      { method: 'POST', body },
    ),
  policies: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/trade-management/policies'),
  monitorTick: () => apiRequest<Record<string, unknown>>('/api/v1/trade-management/monitor/tick', { method: 'POST' }),
  recoverAll: () => apiRequest<Record<string, unknown>>('/api/v1/trade-management/recover', { method: 'POST' }),
}

export const phaseTwelveApi = {
  health: () => apiRequest<{
    analytics: Record<string, unknown>
    backtest: Record<string, unknown>
    phase: number
    live_execution: string
    broker_changing_calls: number
  }>('/api/v1/analytics/health'),
  dashboard: () => apiRequest<{
    snapshot: Record<string, unknown> | null
    metrics: Record<string, unknown> | null
    environment_note: string
    auto_promote_strategies: boolean
    auto_promote_risk: boolean
  }>('/api/v1/analytics/dashboard'),
  datasets: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/analytics/datasets'),
  buildDataset: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/analytics/datasets', { method: 'POST', body }),
  createSnapshot: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/analytics/snapshots', { method: 'POST', body }),
  snapshots: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/analytics/snapshots'),
  trades: (datasetId?: string) =>
    apiRequest<Array<Record<string, unknown>>>(
      `/api/v1/analytics/trades${datasetId ? `?dataset_id=${encodeURIComponent(datasetId)}` : ''}`,
    ),
  reports: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/analytics/reports'),
  compare: (body: { backtest_run_id: string; demo_snapshot_id: string }) =>
    apiRequest<Record<string, unknown>>('/api/v1/analytics/compare', { method: 'POST', body }),
  comparisons: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/analytics/comparisons'),
  createDataSnapshot: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/backtest/data-snapshots', { method: 'POST', body }),
  queueRun: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/backtest/runs', { method: 'POST', body }),
  runs: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/backtest/runs'),
  showRun: (id: string) => apiRequest<Record<string, unknown>>(`/api/v1/backtest/runs/${encodeURIComponent(id)}`),
  evaluation: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/backtest/runs/${encodeURIComponent(id)}/evaluation`),
  queueStats: () => apiRequest<Record<string, unknown>>('/api/v1/backtest/queue'),
  processQueue: (limit = 3) =>
    apiRequest<Record<string, unknown>>('/api/v1/backtest/queue/process', { method: 'POST', body: { limit } }),
}

export const phaseThirteenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/health'),
  desk: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/desk'),
  pulse: (symbols?: string) =>
    apiRequest<Record<string, unknown>>(
      `/api/v1/intelligence/pulse${symbols ? `?symbols=${encodeURIComponent(symbols)}` : ''}`,
    ),
  heatmap: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/heatmap'),
  assessments: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/intelligence/assessments'),
  assess: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/assess', { method: 'POST', body }),
  showAssessment: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/intelligence/assessments/${encodeURIComponent(id)}`),
  opportunities: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/intelligence/opportunities'),
  board: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/board', { method: 'POST', body }),
  calendar: (provider?: string) =>
    apiRequest<Record<string, unknown>>(
      `/api/v1/intelligence/calendar${provider ? `?provider=${encodeURIComponent(provider)}` : ''}`,
    ),
  news: (symbol?: string) =>
    apiRequest<Record<string, unknown>>(
      `/api/v1/intelligence/news${symbol ? `?symbol=${encodeURIComponent(symbol)}` : ''}`,
    ),
  analyze: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/analyze', { method: 'POST', body }),
  chat: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/chat', { method: 'POST', body }),
  calibration: (evidence_label = 'DEMO') =>
    apiRequest<Record<string, unknown>>(
      `/api/v1/intelligence/calibration?evidence_label=${encodeURIComponent(evidence_label)}`,
    ),
  addCalibrationSample: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/calibration/samples', { method: 'POST', body }),
  usage: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/usage'),
  research: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/research'),
  settings: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/settings'),
  updateSettings: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/settings', { method: 'PUT', body }),
  refuseMutate: (action: string) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/mutate', { method: 'POST', body: { action } }),
}

export const phaseSeventeenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/health'),
  desk: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/desk'),
  assess: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/assess', { method: 'POST', body }),
  snapshots: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/intelligence/advanced/snapshots'),
  showSnapshot: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/intelligence/advanced/snapshots/${encodeURIComponent(id)}`),
  mtf: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/mtf', { method: 'POST', body }),
  features: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/features', { method: 'POST', body }),
  analogs: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/analogs', { method: 'POST', body }),
  suitability: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/suitability', { method: 'POST', body }),
  memory: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/memory'),
  postTradeMemory: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/memory/post-trade', { method: 'POST', body }),
  evidence: () => apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/evidence'),
  chat: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/chat', { method: 'POST', body }),
  refuseMutate: (action: string) =>
    apiRequest<Record<string, unknown>>('/api/v1/intelligence/advanced/mutate', { method: 'POST', body: { action } }),
}

export const phaseFourteenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/automation/health'),
  controlCenter: () => apiRequest<Record<string, unknown>>('/api/v1/automation/control-center'),
  preflight: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/automation/preflight', { method: 'POST', body }),
  profiles: () => apiRequest<{ data?: Array<Record<string, unknown>> } | Array<Record<string, unknown>>>('/api/v1/automation/profiles'),
  createProfile: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/automation/profiles', { method: 'POST', body }),
  validateProfile: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/profiles/${encodeURIComponent(id)}/validate`, { method: 'POST', body: {} }),
  activateProfile: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/profiles/${encodeURIComponent(id)}/activate`, { method: 'POST', body: {} }),
  enableAutoDemo: (confirmation_phrase: string) =>
    apiRequest<Record<string, unknown>>('/api/v1/automation/settings/enable-auto-demo', {
      method: 'POST',
      body: { confirmation_phrase },
    }),
  startStep1: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/automation/start/step1', { method: 'POST', body }),
  startStep2: (sessionId: string, confirmation_phrase: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/sessions/${encodeURIComponent(sessionId)}/start/step2`, {
      method: 'POST',
      body: { confirmation_phrase },
    }),
  pause: (sessionId: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/sessions/${encodeURIComponent(sessionId)}/pause`, { method: 'POST', body: {} }),
  resume: (sessionId: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/sessions/${encodeURIComponent(sessionId)}/resume`, { method: 'POST', body: {} }),
  stop: (sessionId: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/sessions/${encodeURIComponent(sessionId)}/stop`, { method: 'POST', body: {} }),
  killSwitch: (sessionId: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/sessions/${encodeURIComponent(sessionId)}/kill-switch`, { method: 'POST', body: {} }),
  tick: (sessionId: string, candidate?: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/automation/sessions/${encodeURIComponent(sessionId)}/tick`, {
      method: 'POST',
      body: { candidate: candidate ?? {} },
    }),
  workflows: () => apiRequest<Record<string, unknown>>('/api/v1/automation/workflows'),
  rejections: () => apiRequest<Record<string, unknown>>('/api/v1/automation/rejections'),
  executions: () => apiRequest<Record<string, unknown>>('/api/v1/automation/executions'),
  events: () => apiRequest<Record<string, unknown>>('/api/v1/automation/events'),
  refuseLiveAuto: () =>
    apiRequest<Record<string, unknown>>('/api/v1/automation/live-auto', { method: 'POST', body: {} }),
}

export const phaseFifteenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/observability/health'),
  publicHealth: () => apiRequest<Record<string, unknown>>('/api/v1/health', { csrf: false }),
  liveness: () => apiRequest<Record<string, unknown>>('/api/v1/health/liveness', { csrf: false }),
  readiness: () => apiRequest<Record<string, unknown>>('/api/v1/health/readiness', { csrf: false }),
  tradingReadiness: () => apiRequest<Record<string, unknown>>('/api/v1/health/trading-readiness', { csrf: false }),
  operations: () => apiRequest<Record<string, unknown>>('/api/v1/observability/operations'),
  metrics: (evidenceLabel?: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/observability/metrics${evidenceLabel ? `?evidence_label=${encodeURIComponent(evidenceLabel)}` : ''}`),
  recordMetric: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/observability/metrics', { method: 'POST', body }),
  healthHistory: () => apiRequest<Record<string, unknown>>('/api/v1/observability/health-history'),
  watchdog: () => apiRequest<Record<string, unknown>>('/api/v1/observability/watchdog', { method: 'POST', body: {} }),
  alerts: (status?: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/observability/alerts${status ? `?status=${encodeURIComponent(status)}` : ''}`),
  raiseAlert: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/observability/alerts', { method: 'POST', body }),
  ackAlert: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/observability/alerts/${encodeURIComponent(id)}/ack`, { method: 'POST', body: {} }),
  resolveAlert: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/observability/alerts/${encodeURIComponent(id)}/resolve`, { method: 'POST', body: {} }),
  validationLab: () => apiRequest<Record<string, unknown>>('/api/v1/observability/validation-lab'),
  startValidation: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/observability/validation-sessions', { method: 'POST', body }),
  observeValidation: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/observability/validation-sessions/${encodeURIComponent(id)}/observe`, { method: 'POST', body }),
  dataQuality: () => apiRequest<Record<string, unknown>>('/api/v1/observability/data-quality'),
  evaluateDataQuality: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/observability/data-quality', { method: 'POST', body }),
  backup: () => apiRequest<Record<string, unknown>>('/api/v1/observability/backup', { method: 'POST', body: {} }),
  disasterRecovery: () => apiRequest<Record<string, unknown>>('/api/v1/observability/disaster-recovery'),
  circuits: () => apiRequest<Record<string, unknown>>('/api/v1/observability/circuits'),
  resources: () => apiRequest<Record<string, unknown>>('/api/v1/observability/resources'),
  scorecard: () => apiRequest<Record<string, unknown>>('/api/v1/observability/scorecard'),
  env: () => apiRequest<Record<string, unknown>>('/api/v1/observability/env'),
  incidents: () => apiRequest<Record<string, unknown>>('/api/v1/observability/incidents'),
  openIncident: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/observability/incidents', { method: 'POST', body }),
  comparisons: () => apiRequest<Record<string, unknown>>('/api/v1/observability/comparisons'),
  risk: () => apiRequest<Record<string, unknown>>('/api/v1/observability/risk'),
  executionQuality: () => apiRequest<Record<string, unknown>>('/api/v1/observability/execution-quality'),
  reconciliation: () => apiRequest<Record<string, unknown>>('/api/v1/observability/reconciliation'),
  queue: () => apiRequest<Record<string, unknown>>('/api/v1/observability/queue'),
  server: () => apiRequest<Record<string, unknown>>('/api/v1/observability/server'),
  failureScenarios: () => apiRequest<Record<string, unknown>>('/api/v1/observability/failure-scenarios'),
  refuseLiveProduction: () =>
    apiRequest<Record<string, unknown>>('/api/v1/observability/live-production', { method: 'POST', body: {} }),
}

export const phaseSixteenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/governance/health'),
  dashboard: () => apiRequest<Record<string, unknown>>('/api/v1/governance/dashboard'),
  lifecycle: () => apiRequest<Record<string, unknown>>('/api/v1/governance/lifecycle'),
  versions: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/governance/versions'),
  registerVersion: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/governance/versions', { method: 'POST', body }),
  transition: (id: string, to: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/governance/versions/${encodeURIComponent(id)}/transition`, {
      method: 'POST', body: { to },
    }),
  openReleaseCandidate: (id: string, body: Record<string, unknown> = {}) =>
    apiRequest<Record<string, unknown>>(`/api/v1/governance/versions/${encodeURIComponent(id)}/release-candidates`, {
      method: 'POST', body,
    }),
  buildEvidence: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/governance/versions/${encodeURIComponent(id)}/evidence`, {
      method: 'POST', body,
    }),
  startApproval: (id: string, action: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/governance/versions/${encodeURIComponent(id)}/approvals`, {
      method: 'POST', body: { action },
    }),
  approvalStep: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/governance/approvals/${encodeURIComponent(id)}/step`, {
      method: 'POST', body,
    }),
  lab: () => apiRequest<Record<string, unknown>>('/api/v1/governance/lab'),
  runLab: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/governance/lab/experiments', { method: 'POST', body }),
  compare: (left: string, right: string) =>
    apiRequest<Record<string, unknown>>('/api/v1/governance/comparisons', {
      method: 'POST', body: { left_public_id: left, right_public_id: right },
    }),
  refuseLiveDeploy: () =>
    apiRequest<Record<string, unknown>>('/api/v1/governance/live-deploy', { method: 'POST', body: {} }),
  refuseAiApprove: () =>
    apiRequest<Record<string, unknown>>('/api/v1/governance/ai-approve', { method: 'POST', body: {} }),
}

export const phaseEighteenApi = {
  health: () => apiRequest<Record<string, unknown>>('/api/v1/fleet/health'),
  dashboard: () => apiRequest<Record<string, unknown>>('/api/v1/fleet/dashboard'),
  registerProvider: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/providers', { method: 'POST', body }),
  registerConnection: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/connections', { method: 'POST', body }),
  registerAccount: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/accounts', { method: 'POST', body }),
  verifyAccount: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/verify`, { method: 'POST', body: {} }),
  registerTerminal: (id: string, node_label: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/terminals`, {
      method: 'POST', body: { node_label },
    }),
  mapInstrument: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/instruments/map', { method: 'POST', body }),
  createPortfolio: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/portfolios', { method: 'POST', body }),
  addMembership: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/portfolios/${encodeURIComponent(id)}/memberships`, {
      method: 'POST', body,
    }),
  activateAllocation: (id: string, weights: Record<string, number>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/portfolios/${encodeURIComponent(id)}/allocations`, {
      method: 'POST', body: { weights },
    }),
  portfolioAnalytics: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/portfolios/${encodeURIComponent(id)}/analytics`),
  assignStrategy: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/assignments`, {
      method: 'POST', body,
    }),
  createRiskLock: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/risk-locks', { method: 'POST', body }),
  routeExecution: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/route`, {
      method: 'POST', body,
    }),
  managementGate: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/management-gate`, {
      method: 'POST', body,
    }),
  reconcile: (id: string, body: Record<string, unknown> = {}) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/reconcile`, {
      method: 'POST', body,
    }),
  captureHealth: () =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/health/capture', { method: 'POST', body: {} }),
  automationScope: (id: string, mode: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/accounts/${encodeURIComponent(id)}/automation-scope`, {
      method: 'POST', body: { mode },
    }),
  emergency: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/emergency', { method: 'POST', body }),
  valuation: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/valuation', { method: 'POST', body }),
  registerNode: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/nodes', { method: 'POST', body }),
  acquireLease: (nodeId: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/fleet/nodes/${encodeURIComponent(nodeId)}/leases`, {
      method: 'POST', body,
    }),
  refuseAiRoute: () =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/ai-route', { method: 'POST', body: {} }),
  refuseLiveAuto: () =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/live-auto', { method: 'POST', body: {} }),
  refuseCopyTrading: () =>
    apiRequest<Record<string, unknown>>('/api/v1/fleet/copy-trading', { method: 'POST', body: {} }),
}

export const phaseNineteenApi = {
  matrix: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/matrix'),
  ops: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/ops'),
  tradingReadiness: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/trading-readiness'),
  environments: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/environments'),
  validateEnvironments: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/environments/validate', { method: 'POST', body }),
  secrets: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/secrets'),
  rotateSecret: (secret_key: string) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/secrets/rotate', { method: 'POST', body: { secret_key } }),
  identity: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/identity'),
  security: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/security'),
  queues: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/queues'),
  enqueue: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/queues', { method: 'POST', body }),
  workers: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/workers'),
  registerWorker: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/workers', { method: 'POST', body }),
  workerAction: (id: string, action: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/hardening/workers/${encodeURIComponent(id)}`, {
      method: 'POST', body: { action },
    }),
  dr: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/dr'),
  backup: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/backup', { method: 'POST', body: {} }),
  isolatedRestore: (body: Record<string, unknown> = {}) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/isolated-restore', { method: 'POST', body }),
  deploy: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/deploy'),
  registerDeploy: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/deploy', { method: 'POST', body }),
  deployAction: (id: string, body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>(`/api/v1/hardening/deploy/${encodeURIComponent(id)}`, {
      method: 'POST', body,
    }),
  failover: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/failover'),
  safeModes: () => apiRequest<Array<Record<string, unknown>>>('/api/v1/hardening/safe-modes'),
  activateSafeMode: (body: Record<string, unknown>) =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/safe-modes', { method: 'POST', body }),
  clearSafeMode: (id: string) =>
    apiRequest<Record<string, unknown>>(`/api/v1/hardening/safe-modes/${encodeURIComponent(id)}/clear`, {
      method: 'POST', body: {},
    }),
  capacity: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/capacity'),
  soak: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/soak'),
  runSoak: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/soak/run', { method: 'POST', body: {} }),
  windows: () => apiRequest<Record<string, unknown>>('/api/v1/hardening/windows'),
  refuseAiMutation: () =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/ai-mutate', { method: 'POST', body: {} }),
  refuseLiveAuto: () =>
    apiRequest<Record<string, unknown>>('/api/v1/hardening/live-auto', { method: 'POST', body: {} }),
}

