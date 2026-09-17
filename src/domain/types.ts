export type TradingEnvironment = 'SIMULATION' | 'PAPER' | 'DEMO' | 'LIVE'
export type Direction = 'BUY' | 'SELL'
export type AssetClass = 'Forex' | 'Metals' | 'Indices' | 'Crypto'
export type Timeframe = 'M1' | 'M5' | 'M15' | 'M30' | 'H1' | 'H4' | 'D1'

export interface BrokerAccount { id: string; name: string; broker: string; platform: 'MetaTrader 5'; server: string; environment: TradingEnvironment; currency: string; leverage: string; status: 'NOT CONNECTED' }
export interface SymbolInfo { code: string; name: string; assetClass: AssetClass; digits: number; favorite: boolean }
export interface Quote { symbol: string; bid: number; ask: number; spread: number; change: number; high: number; low: number; trend: 'Bullish' | 'Bearish' | 'Neutral'; volatility: 'Low' | 'Medium' | 'High'; marketStatus: 'OPEN' | 'CLOSED' }
export interface Candle { time: number; open: number; high: number; low: number; close: number; volume: number }
export interface Strategy { id: string; name: string; category: string; symbols: string[]; timeframe: Timeframe; mode: 'SIMULATION'; status: 'Enabled' | 'Disabled' | 'Archived'; signals: number; trades: number; winRate: number; profitFactor: number; description: string }
export interface Signal { id: string; symbol: string; direction: Direction | 'NO TRADE'; score: number; timeframe: Timeframe; regime: string; strategy: string; entry: number; stopLoss: number; takeProfit1: number; takeProfit2: number; riskReward: number; simulated: true; analysis: Record<string, string> }
export interface Order { id: string; ticket: string; symbol: string; direction: Direction; type: 'Market' | 'Buy Limit' | 'Sell Limit' | 'Buy Stop' | 'Sell Stop'; volume: number; entry: number; stopLoss: number; takeProfit: number; status: 'PENDING' | 'FILLED' | 'CANCELLED'; expiry?: string; strategy?: string; createdAt?: string; simulated: true }
export interface Deal { id: string; orderId: string; price: number; volume: number; commission: number; executedAt: string; simulated: true }
export interface Position { id: string; ticket: string; symbol: string; direction: Direction; strategy: string; volume: number; entry: number; current: number; stopLoss: number; takeProfit: number; profit: number; pips: number; risk: number; openedAt: string; status: 'OPEN' | 'CLOSED'; simulated: true }
export interface RiskProfile { riskPerTrade: number; maxLotSize: number; maxDailyLoss: number; maxWeeklyLoss: number; maxDrawdown: number; maxOpenPositions: number; maxOpenRisk: number; maxTradesPerDay: number; maxConsecutiveLosses: number; minMarginLevel: number; maxSpread: number; maxSlippage: number; minRiskReward: number }
export interface RiskEvent { id: string; severity: 'INFO' | 'WARNING' | 'CRITICAL'; title: string; detail: string; occurredAt: string }
export interface AccountSnapshot { balance: number; equity: number; todayPnl: number; weeklyPnl: number; floatingPnl: number; freeMargin: number; marginLevel: number; drawdown: number; openPositions: number; pendingOrders: number; activeStrategies: number; todayTrades: number; winRate: number; openRisk: number }
export interface Trade { ticket: string; symbol: string; direction: Direction; strategy: string; volume: number; entry: number; exit: number; stopLoss: number; takeProfit: number; grossPnl: number; commission: number; swap: number; netPnl: number; duration: string; closedAt: string }
export interface AppNotification { id: string; category: 'Trade' | 'Signal' | 'Risk' | 'Strategy' | 'MT5' | 'News' | 'System' | 'Security'; title: string; body: string; time: string; read: boolean }
export interface ServiceHealth { name: string; state: 'ONLINE' | 'SIMULATION' | 'NOT CONNECTED' | 'MOCK'; detail: string }
export interface SystemHealth { services: ServiceHealth[]; lastHeartbeat: string; lastMarketUpdate: string; lastSignal: string; version: string }
export interface PaperTradingSnapshot { balance: number; equity: number; profit: number; positions: Position[] }
export interface NewsEvent { id: string; time: string; currency: string; impact: 'Low' | 'Medium' | 'High'; event: string; previous: string; forecast: string; actual: string }
export interface AuditEvent { id: string; timestamp: string; user: string; action: string; module: string; description: string; ip: string; result: 'SUCCESS' | 'BLOCKED' }
export interface BacktestConfig { strategy: string; symbol: string; timeframe: Timeframe; startDate: string; endDate: string; initialBalance: number; risk: number; spread: number; commission: number; slippage: number }
export interface BacktestResult { netProfit: number; returnPercent: number; totalTrades: number; winningTrades: number; losingTrades: number; winRate: number; profitFactor: number; maxDrawdown: number; averageWin: number; averageLoss: number; expectancy: number; sharpe: number; recoveryFactor: number }
