import { apiRequest } from './client'
import type {
  AppSetting, AuditRecord, BrokerAccountRecord, CurrentUser, DashboardSummary, NotificationRecord,
  Paginated, Preference, RiskProfileRecord, SimulationOrderInput, SimulationOrderRecord, StrategyRecord,
  SystemStatus, UserRecord, TradingInstrument, Signal, TradeIntent, CreateTradeIntentInput,
  CreateSignalIntentInput, ExecutionCommand, Order, Position, ServiceHeartbeat,
  Mt5BridgeStatus, Mt5BridgeConnection, Mt5BridgeEnvelope, Mt5ExternalPosition, Mt5ReconciliationRun,
  MarketSnapshot, MarketQuote, MarketCandleBar, MarketSymbolInfo, MarketExtensionHooks,
  IndicatorCatalogItem, IndicatorResult,
  StrategyPluginCatalogItem, StrategyScanResult,
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
