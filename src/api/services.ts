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
