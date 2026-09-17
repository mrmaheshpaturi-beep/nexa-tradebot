export interface Permission { id: number; name: string; label: string }
export interface Role { id: number; name: string; label: string; permissions?: Permission[] }
export interface Preference {
  id: number
  timezone: string
  locale: 'en'
  theme: 'light' | 'dark' | 'system'
  sidebar_collapsed: boolean
  default_dashboard: 'overview' | 'trading' | 'risk'
  favorite_symbols: string[] | null
  default_timeframe: string
  table_page_size: 10 | 25 | 50 | 100
  notifications_enabled: boolean
}
export interface CurrentUser {
  id: number
  name: string
  email: string
  status: 'ACTIVE' | 'SUSPENDED' | 'DISABLED'
  last_login_at?: string | null
  roles: Role[]
  preference?: Preference | null
}
export interface Paginated<T> {
  current_page: number
  data: T[]
  last_page: number
  per_page: number
  total: number
}
export interface DashboardSummary {
  strategies: number
  broker_accounts: number
  simulation_orders: number
  unread_notifications: number
  latest_account_snapshot: AccountSnapshot | null
  system_events: number
  audit_logs: number
}
export interface AccountSnapshot {
  id: number
  balance: string | number
  equity: string | number
  margin: string | number
  free_margin: string | number
  margin_level: string | number | null
  floating_pnl: string | number
  drawdown: string | number
  captured_at: string
}
export interface StrategyRecord {
  id: number
  name: string
  slug: string
  category: string
  description: string | null
  status: string
  mode: string
  version: number
  minimum_signal_score: string | number
  symbols: string[]
  timeframes: string[]
  sessions: string[] | null
  enabled: boolean
  auto_trading_enabled: false
}
export interface RiskProfileRecord {
  id: number
  name: string
  status: string
  is_default: boolean
  max_risk_per_trade: string | number
  max_lot_size: string | number
  max_daily_loss: string | number
  max_weekly_loss: string | number
  max_drawdown: string | number
  max_open_positions: number
  max_open_risk: string | number
  max_trades_per_day: number
  max_consecutive_losses: number
  min_margin_level: string | number
  max_spread: string | number
  max_slippage: string | number
  min_reward_risk: string | number
}
export interface BrokerAccountRecord {
  id: number
  name: string
  broker: string | null
  platform: 'NONE' | 'SIMULATION'
  server: string | null
  account_reference: string | null
  environment: 'SIMULATION'
  currency: string
  leverage: number
  status: 'DISCONNECTED' | 'READY' | 'DISABLED'
  is_enabled: boolean
  metadata: Record<string, unknown> | null
}
export interface AppSetting { id: number; key: string; group: string; value: unknown; is_public: boolean }
export interface NotificationRecord {
  id: string
  category: string
  severity: string
  title: string
  message: string
  is_read: boolean
  read_at: string | null
  created_at: string
}
export interface AuditRecord {
  id: number
  action: string
  module: string
  description: string
  result: string
  ip_address: string | null
  occurred_at: string
  user: Pick<CurrentUser, 'id' | 'name' | 'email'> | null
}
export interface SystemStatus {
  environment: 'SIMULATION'
  database: { status: 'CONNECTED' | 'UNAVAILABLE'; source: 'DATABASE' }
  market_data: { status: 'MOCK'; source: 'MOCK MARKET DATA' }
  simulation_engine: { status: 'READY' | 'STOPPED'; source: 'SIMULATION ENGINE' }
  broker: { status: 'DISCONNECTED'; connected: false }
  execution: { available: false; broker_transmission: false }
  allow_demo_execution: false
  allow_live_execution: false
  emergency_stop: boolean
  trading_enabled: boolean
}
export interface UserRecord extends CurrentUser { preference?: Preference }
export interface SimulationOrderInput {
  command_id: string
  idempotency_key: string
  symbol: string
  direction: 'BUY' | 'SELL'
  volume: number
  requested_price?: number
  risk_percent?: number
  stop_loss?: number
  take_profit?: number
  comment?: string
  broker_account_id?: number
}
export interface SimulationOrderRecord extends SimulationOrderInput {
  id: number
  public_id: string
  status: 'SIMULATED'
  environment: 'SIMULATION'
  simulated: true
  broker_transmitted: false
  idempotent_replay: boolean
}
