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
  public_id: string
  name: string
  broker: string | null
  platform: 'NONE' | 'SIMULATION'
  server: string | null
  account_reference: string | null
  environment: 'SIMULATION'
  currency: string
  leverage: number
  status: 'CONFIGURED' | 'CONNECTING' | 'CONNECTED' | 'DISCONNECTED' | 'ERROR' | 'DISABLED'
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
  web_application: { status: 'ONLINE' }
  database: { status: 'CONNECTED' | 'UNAVAILABLE'; source: 'DATABASE' }
  authentication: { status: 'ONLINE' }
  market_data: { status: 'MOCK'; source: 'MOCK MARKET DATA' }
  trading_engine: { status: 'READY' | 'STOPPED'; mode: 'SIMULATION' }
  signal_engine: { status: 'SIMULATION' }
  risk_execution: { status: 'READY' | 'STOPPED'; mode: 'SIMULATION_ONLY' }
  simulation_engine: { status: 'READY' | 'STOPPED'; source: 'SIMULATION ENGINE'; last_heartbeat_at: string | null }
  terminal: { status: 'OFFLINE'; adapter: 'SIMULATION' }
  broker: { status: 'DISCONNECTED'; connected: false }
  execution: { available: boolean; environment: 'SIMULATION'; broker_transmission: false }
  simulation_execution_enabled: boolean
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

export type TradingEnvironment = 'SIMULATION' | 'PAPER' | 'DEMO' | 'LIVE'
export type Direction = 'BUY' | 'SELL'
export type Timeframe = 'M1' | 'M5' | 'M15' | 'M30' | 'H1' | 'H4' | 'D1'
export type OrderType = 'MARKET' | 'BUY_LIMIT' | 'SELL_LIMIT' | 'BUY_STOP' | 'SELL_STOP'
export type TimeInForce = 'GTC' | 'DAY' | 'IOC' | 'FOK'
export type TradeOrigin = 'MANUAL' | 'SIGNAL' | 'SIMULATION' | 'SYSTEM'
export type SignalStatus = 'GENERATED' | 'VALID' | 'CONSUMED' | 'EXPIRED' | 'REJECTED' | 'CANCELLED'
export type TradeIntentStatus = 'DRAFT' | 'PENDING_RISK' | 'RISK_APPROVED' | 'RISK_REJECTED' | 'CANCELLED' | 'EXPIRED' | 'COMMAND_CREATED'
export type RiskDecisionStatus = 'APPROVED' | 'REJECTED'
export type ExecutionCommandStatus = 'CREATED' | 'QUEUED' | 'PROCESSING' | 'ACKNOWLEDGED' | 'COMPLETED' | 'FAILED' | 'CANCELLED' | 'EXPIRED'
export type ExecutionCommandType = 'PLACE_ORDER' | 'MODIFY_ORDER' | 'CANCEL_ORDER' | 'CLOSE_POSITION' | 'PARTIAL_CLOSE' | 'MODIFY_POSITION_SL' | 'MODIFY_POSITION_TP'
export type OrderStatus = 'CREATED' | 'SUBMITTED' | 'ACCEPTED' | 'PARTIALLY_FILLED' | 'FILLED' | 'CANCEL_PENDING' | 'CANCELLED' | 'SIMULATED' | 'REJECTED' | 'EXPIRED' | 'FAILED'
export type PositionStatus = 'OPEN' | 'PARTIALLY_CLOSED' | 'CLOSED'
export type PositionEventType = 'OPENED' | 'VOLUME_INCREASED' | 'PARTIALLY_CLOSED' | 'STOP_LOSS_MODIFIED' | 'TAKE_PROFIT_MODIFIED' | 'BREAK_EVEN_APPLIED' | 'TRAILING_STOP_UPDATED' | 'CLOSED' | 'RECONCILED'
export type DealType = 'ENTRY' | 'EXIT' | 'PARTIAL_EXIT'
export type RiskReasonCode = 'APPROVED' | 'DAILY_LOSS_LIMIT' | 'WEEKLY_LOSS_LIMIT' | 'DRAWDOWN_LIMIT' | 'MAX_POSITIONS' | 'MAX_EXPOSURE' | 'MAX_LOT' | 'SPREAD_LIMIT' | 'SLIPPAGE_LIMIT' | 'MARGIN_LIMIT' | 'CONSECUTIVE_LOSS_LIMIT' | 'MINIMUM_RR' | 'EMERGENCY_STOP' | 'TRADING_DISABLED' | 'SESSION_RESTRICTED' | 'NEWS_RESTRICTED' | 'CORRELATION_LIMIT' | 'VALIDATION_FAILURE' | 'SIMULATION_EXECUTION_DISABLED' | 'INVALID_ENVIRONMENT' | 'INVALID_ACCOUNT' | 'INVALID_INSTRUMENT' | 'INVALID_VOLUME' | 'INVALID_PROTECTION' | 'RISK_LIMIT' | 'REWARD_RISK' | 'MAX_OPEN_POSITIONS' | 'MISSING_ACCOUNT_SNAPSHOT'
export type Numeric = string | number

export interface BackendMockQuote {
  symbol: string; bid: Numeric; ask: Numeric; spread: Numeric; timestamp: string
  source: 'MOCK'; environment: 'SIMULATION'
}

export interface TradingInstrument {
  id: number; public_id: string; symbol: string; name: string; display_name: string
  asset_class: 'FOREX' | 'METAL' | 'INDEX' | 'CRYPTO' | 'COMMODITY' | 'OTHER' | 'EQUITY'
  currency_base: string; currency_quote: string; base_currency: string; quote_currency: string
  digits: number; point_size: Numeric; contract_size: Numeric; tick_size: Numeric; tick_value: Numeric
  volume_min: Numeric; volume_max: Numeric; volume_step: Numeric; minimum_volume: Numeric
  maximum_volume: Numeric; step_volume: Numeric; minimum_stop_distance: Numeric; margin_rate: Numeric
  is_enabled: boolean; mock_quote: BackendMockQuote; created_at: string; updated_at: string
}

export interface Signal {
  id: number; public_id: string; user_id: number; broker_account_id: number | null
  trading_strategy_id: number | null; trading_instrument_id: number | null
  symbol: string; direction: Direction | 'NEUTRAL'; timeframe: Timeframe | null; score: Numeric | null
  entry_price: Numeric | null; entry_reference: Numeric | null; stop_loss: Numeric | null
  take_profit_1: Numeric | null; take_profit_1_reference: Numeric | null
  take_profit_2: Numeric | null; take_profit_2_reference: Numeric | null; risk_reward: Numeric | null
  market_regime: string | null; status: SignalStatus; source: 'MOCK' | 'SIMULATION'
  explanation: string | null; environment: TradingEnvironment; generated_at: string
  expires_at: string | null; consumed_at: string | null; metadata: Record<string, unknown> | null
  strategy?: StrategyRecord | null; instrument?: TradingInstrument | null; intent?: TradeIntent | null
}

export interface TradeIntent {
  id: number; public_id: string; user_id: number; broker_account_id: number
  trading_strategy_id: number | null; signal_id: number | null; trading_instrument_id: number
  idempotency_key: string; origin: TradeOrigin; side: Direction; order_type: OrderType
  volume: Numeric; requested_volume: Numeric; requested_price: Numeric | null; requested_entry: Numeric | null
  stop_loss: Numeric | null; take_profit: Numeric | null; take_profit_2: Numeric | null
  time_in_force: TimeInForce; comment: string | null; risk_percent: Numeric | null
  created_by: number | null; status: TradeIntentStatus; environment: TradingEnvironment
  metadata: Record<string, unknown> | null; created_at: string; updated_at: string; idempotent_replay?: boolean
  instrument?: TradingInstrument; broker_account?: BrokerAccountRecord; strategy?: StrategyRecord | null
  signal?: Signal | null; risk_decision?: RiskDecision | null; execution_command?: ExecutionCommand | null
}

export interface RiskDecision {
  id: number; public_id: string; trade_intent_id: number; risk_profile_id: number | null
  status: RiskDecisionStatus; decision: RiskDecisionStatus; reason_code: RiskReasonCode
  message: string; reason: string; risk_amount: Numeric; requested_risk: Numeric
  approved_risk: Numeric | null; requested_volume: Numeric; approved_volume: Numeric | null
  reward_risk: Numeric | null; checks: Record<string, unknown>; evaluated_at: string
  created_at: string; updated_at: string
}

export interface ExecutionCommand {
  id: number; public_id: string; user_id: number; broker_account_id: number
  trade_intent_id: number | null; position_id: number | null; idempotency_key: string
  type: ExecutionCommandType; status: ExecutionCommandStatus; environment: TradingEnvironment
  symbol: string; side: Direction | null; order_type: OrderType | null; volume: Numeric | null
  price: Numeric | null; stop_loss: Numeric | null; take_profit: Numeric | null; expiration: string | null
  attempt_count: number; failure_code: string | null; safe_error: string | null; error_code: string | null
  error_message: string | null; payload: Record<string, unknown> | null; requested_at: string
  acknowledged_at: string | null; completed_at: string | null; failed_at: string | null
  created_at: string; updated_at: string; idempotent_replay?: boolean; order?: Order | null
}

export interface Order {
  id: number; public_id: string; correlation_id: string; command_id: string
  execution_command_id: number | null; trade_intent_id: number | null; user_id: number
  broker_account_id: number | null; signal_id: number | null; trading_instrument_id: number | null
  trading_strategy_id: number | null; idempotency_key: string; external_order_id: string | null
  symbol: string; direction: Direction; side: Direction; type: OrderType; order_type: OrderType
  volume: Numeric; requested_volume: Numeric; filled_volume: Numeric; remaining_volume: Numeric
  requested_price: Numeric | null; fill_price: Numeric | null; average_fill_price: Numeric | null
  risk_amount: Numeric | null; risk_percent: Numeric | null; stop_loss: Numeric | null
  take_profit: Numeric | null; comment: string | null; status: OrderStatus; environment: TradingEnvironment
  simulated: boolean; broker_transmitted: boolean; metadata: Record<string, unknown> | null
  requested_at: string | null; submitted_at: string | null; accepted_at: string | null
  filled_at: string | null; cancelled_at: string | null; rejected_at: string | null
  expired_at: string | null; failed_at: string | null; created_at: string; updated_at: string
  instrument?: TradingInstrument | null; deals?: Deal[]; position?: Position | null
  execution_command?: ExecutionCommand | null
}

export interface Deal {
  id: number; public_id: string; order_id: number; broker_account_id: number | null
  position_id: number | null; execution_command_id: number | null; symbol: string
  direction: Direction; side: Direction; volume: Numeric; price: Numeric
  commission: Numeric; swap: Numeric; fee: Numeric; profit: Numeric; type: DealType
  origin: TradeOrigin; environment: TradingEnvironment; dealt_at: string
  executed_at: string | null; metadata: Record<string, unknown> | null
  created_at?: string; updated_at?: string
}

export interface Position {
  id: number; public_id: string; user_id: number; broker_account_id: number
  opening_order_id: number | null; trading_instrument_id: number | null; trading_strategy_id: number | null
  signal_id: number | null; external_position_id: string | null; symbol: string
  direction: Direction; side: Direction; volume: Numeric; initial_volume: Numeric; current_volume: Numeric
  open_price: Numeric; average_entry_price: Numeric | null; current_price: Numeric | null
  stop_loss: Numeric | null; take_profit: Numeric | null; realized_pnl: Numeric
  floating_pnl: Numeric; unrealized_pnl: Numeric; margin_used: Numeric; status: PositionStatus
  environment: TradingEnvironment; metadata: Record<string, unknown> | null
  opened_at: string | null; closed_at: string | null; created_at: string; updated_at: string
  instrument?: TradingInstrument | null; broker_account?: BrokerAccountRecord
  opening_order?: Order | null; deals?: Deal[]; events?: PositionEvent[]
}

export interface PositionEvent {
  id: number; public_id: string; position_id: number; execution_command_id: number | null
  type: PositionEventType; origin: TradeOrigin; source: 'SIMULATION'
  previous_state: Record<string, unknown> | null; new_state: Record<string, unknown> | null
  volume_before: Numeric | null; volume_after: Numeric | null; price: Numeric | null
  realized_pnl: Numeric; changes: Record<string, unknown> | null; occurred_at: string
  created_at: string; updated_at: string; execution_command?: ExecutionCommand | null
}

export interface ServiceHeartbeat {
  id: number; public_id: string; trading_terminal_id: number | null; service: string
  instance_id: string; status: 'UNKNOWN' | 'ONLINE' | 'DEGRADED' | 'OFFLINE' | 'ERROR'
  environment: TradingEnvironment; observed_at: string; last_seen_at: string
  details: Record<string, unknown> | null; metadata: Record<string, unknown> | null
}

export interface Quote {
  symbol: string; bid: number; ask: number; spread: number; change: number
  high: number; low: number; trend: 'Bullish' | 'Bearish' | 'Neutral'
  volatility: 'Low' | 'Medium' | 'High'; marketStatus: 'OPEN' | 'CLOSED'; source: 'MOCK'
}
export interface Candle {
  time: number; open: number; high: number; low: number; close: number; volume: number; source: 'MOCK'
}

export interface CreateTradeIntentInput {
  account_public_id: string; instrument_public_id: string; strategy_id?: number | null
  idempotency_key: string; origin?: TradeOrigin; side: Direction; order_type: OrderType
  requested_volume: number; requested_entry?: number | null; stop_loss?: number | null
  take_profit?: number | null; take_profit_2?: number | null; time_in_force?: TimeInForce
  comment?: string; risk_percent?: number | null; metadata?: Record<string, unknown>
}
export interface CreateSignalIntentInput {
  account_public_id: string; idempotency_key: string; order_type?: OrderType
  requested_volume: number; requested_entry?: number | null; stop_loss?: number | null
  take_profit?: number | null; take_profit_2?: number | null; time_in_force?: TimeInForce
  comment?: string; risk_percent?: number | null
}
