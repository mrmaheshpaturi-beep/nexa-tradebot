import { apiRequest } from './client'
import type {
  AppSetting, AuditRecord, BrokerAccountRecord, CurrentUser, DashboardSummary, NotificationRecord,
  Paginated, Preference, RiskProfileRecord, SimulationOrderInput, SimulationOrderRecord, StrategyRecord,
  SystemStatus, UserRecord,
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
