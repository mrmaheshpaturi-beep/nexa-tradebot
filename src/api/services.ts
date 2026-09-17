import { apiRequest } from './client'
import type {
  AppSetting, AuditRecord, BrokerAccountRecord, CurrentUser, DashboardSummary, NotificationRecord,
  Paginated, Preference, RiskProfileRecord, SimulationOrderInput, SimulationOrderRecord, StrategyRecord,
  SystemStatus, UserRecord, TradingInstrument, Signal, TradeIntent, CreateTradeIntentInput,
  CreateSignalIntentInput, ExecutionCommand, Order, Position, ServiceHeartbeat,
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
