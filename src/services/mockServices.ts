import type { AccountSnapshot, AppNotification, AuditEvent, BacktestConfig, BacktestResult, BrokerAccount, Candle, NewsEvent, Order, PaperTradingSnapshot, Position, Quote, RiskEvent, RiskProfile, Signal, Strategy, SystemHealth, Trade } from '../domain/types'

export interface MarketDataService { getQuotes(): Promise<Quote[]>; getCandles(symbol: string): Promise<Candle[]> }
export interface AccountService { getSnapshot(): Promise<AccountSnapshot>; getAccounts(): Promise<BrokerAccount[]> }
export interface SignalService { getSignals(): Promise<Signal[]> }
export interface StrategyService { getStrategies(): Promise<Strategy[]> }
export interface PositionService { getPositions(): Promise<Position[]> }
export interface SimulationOrderInput { symbol: string; direction: 'BUY' | 'SELL'; orderType: 'Market' | 'Limit' | 'Stop'; volume: number; riskPercent: number; comment: string }
export interface SimulationOrderResult { ticket: string; accepted: true; simulated: true; input: SimulationOrderInput }
export interface OrderService { getPendingOrders(): Promise<Order[]>; simulateOrder(input: SimulationOrderInput): Promise<SimulationOrderResult> }
export interface RiskService { getProfile(): Promise<RiskProfile>; getEvents(): Promise<RiskEvent[]> }
export interface BacktestService { run(config: BacktestConfig): Promise<BacktestResult> }
export interface AnalyticsService { getEquitySeries(): Promise<{ name: string; value: number }[]> }
export interface NotificationService { getNotifications(): Promise<AppNotification[]> }
export interface SystemHealthService { getHealth(): Promise<SystemHealth> }
export interface TradeService { getTrades(): Promise<Trade[]> }
export interface PaperTradingService { getSnapshot(): Promise<PaperTradingSnapshot> }
export interface NewsService { getEvents(): Promise<NewsEvent[]> }
export interface AuditService { getEvents(): Promise<AuditEvent[]> }

const wait = <T>(value: T) => new Promise<T>((resolve) => setTimeout(() => resolve(value), 180))
const symbols = [
  ['XAUUSD', 2642.18, 'Metals'], ['EURUSD', 1.11482, 'Forex'], ['GBPUSD', 1.32741, 'Forex'], ['USDJPY', 142.384, 'Forex'],
  ['USDCHF', .84712, 'Forex'], ['AUDUSD', .68146, 'Forex'], ['USDCAD', 1.35812, 'Forex'], ['NZDUSD', .62317, 'Forex'],
  ['US30', 42182.4, 'Indices'], ['NAS100', 19746.2, 'Indices'], ['SPX500', 5712.8, 'Indices'], ['BTCUSD', 62481.3, 'Crypto'], ['ETHUSD', 2456.2, 'Crypto'],
] as const

export class MockMarketDataService implements MarketDataService {
  getQuotes = () => wait(symbols.map(([symbol, bid], i) => ({
    symbol, bid, ask: +(bid + (bid > 100 ? .4 : .00018)).toFixed(bid > 100 ? 2 : 5), spread: i % 3 === 0 ? 1.8 : .9,
    change: +(((i % 5) - 2) * .34).toFixed(2), high: +(bid * 1.006).toFixed(3), low: +(bid * .994).toFixed(3),
    trend: i % 3 === 0 ? 'Bullish' : i % 3 === 1 ? 'Bearish' : 'Neutral', volatility: i % 4 === 0 ? 'High' : 'Medium', marketStatus: 'OPEN',
  })) as Quote[])
  getCandles = async (symbol: string) => {
    void symbol
    return wait(Array.from({ length: 80 }, (_, i) => {
    const base = 2618 + i * .31 + Math.sin(i / 4) * 7
    const close = base + Math.sin(i) * 2
    return { time: Math.floor(Date.now() / 1000) - (80 - i) * 3600, open: base, high: Math.max(base, close) + 2.4, low: Math.min(base, close) - 2.1, close, volume: 600 + i * 13 }
    }))
  }
}

export class MockAccountService implements AccountService {
  getSnapshot = () => wait({ balance: 125480.6, equity: 126214.9, todayPnl: 1842.3, weeklyPnl: 6284.7, floatingPnl: 734.3, freeMargin: 118904.2, marginLevel: 1846.2, drawdown: 2.4, openPositions: 5, pendingOrders: 3, activeStrategies: 7, todayTrades: 12, winRate: 68.4, openRisk: 3.2 })
  getAccounts = () => wait<BrokerAccount[]>([{ id: 'acc-1', name: 'XM Demo', broker: 'XM', platform: 'MetaTrader 5', server: 'XMGlobal-MT5 Demo', environment: 'DEMO', currency: 'USD', leverage: '1:500', status: 'NOT CONNECTED' }])
}

const analysis = { Trend: 'Bullish structure above EMA 50', 'HTF alignment': 'H4 and D1 aligned', Momentum: 'RSI 61, positive', 'Market structure': 'Higher highs intact', Volatility: 'ATR within normal range', Volume: 'Above 20-period average', 'Support / resistance': 'Room to next resistance', 'Strategy agreement': '3 of 4 simulated checks', 'Risk status': 'Within simulation profile' }
export class MockSignalService implements SignalService {
  getSignals = () => wait([
    { id: 'sig-1', symbol: 'XAUUSD', direction: 'BUY', score: 91, timeframe: 'H1', regime: 'Trending', strategy: 'EMA Pullback', entry: 2642.2, stopLoss: 2631.4, takeProfit1: 2658.4, takeProfit2: 2671.8, riskReward: 2.7, simulated: true, analysis },
    { id: 'sig-2', symbol: 'EURUSD', direction: 'SELL', score: 84, timeframe: 'H4', regime: 'Breakout', strategy: 'Market Structure', entry: 1.1148, stopLoss: 1.1192, takeProfit1: 1.1082, takeProfit2: 1.104, riskReward: 2.4, simulated: true, analysis },
    { id: 'sig-3', symbol: 'NAS100', direction: 'NO TRADE', score: 46, timeframe: 'M15', regime: 'Range', strategy: 'Range Breakout', entry: 19746, stopLoss: 19690, takeProfit1: 19830, takeProfit2: 19910, riskReward: 1.5, simulated: true, analysis },
  ] as Signal[])
}
export class MockStrategyService implements StrategyService {
  getStrategies = () => wait(['EMA Trend', 'EMA Pullback', 'RSI Reversal', 'MACD Momentum', 'London Breakout', 'Range Breakout', 'Gold Scalper', 'Market Structure', 'Liquidity Sweep'].map((name, i) => ({ id: `st-${i}`, name, category: ['Trend', 'Trend', 'Reversal', 'Momentum', 'Breakout', 'Breakout', 'Scalping', 'Market Structure', 'AI Assisted'][i], symbols: i === 6 ? ['XAUUSD'] : ['EURUSD', 'XAUUSD'], timeframe: ['H1', 'M15', 'H4'][i % 3] as Strategy['timeframe'], mode: 'SIMULATION', status: i === 2 ? 'Disabled' : 'Enabled', signals: 18 + i * 3, trades: 12 + i, winRate: 54 + i * 2.1, profitFactor: 1.18 + i * .08, description: `${name} is a deterministic demonstration strategy. It produces simulated signals only.` })) as Strategy[])
}
export class MockPositionService implements PositionService {
  getPositions = () => wait<Position[]>([
    { id: 'p1', ticket: 'SIM-10482', symbol: 'XAUUSD', direction: 'BUY', strategy: 'EMA Pullback', volume: .4, entry: 2638.4, current: 2642.2, stopLoss: 2629, takeProfit: 2661, profit: 152, pips: 38, risk: .8, openedAt: '2h 18m', status: 'OPEN', simulated: true },
    { id: 'p2', ticket: 'SIM-10479', symbol: 'EURUSD', direction: 'SELL', strategy: 'Market Structure', volume: 1.2, entry: 1.1172, current: 1.1148, stopLoss: 1.121, takeProfit: 1.108, profit: 288, pips: 24, risk: 1, openedAt: '5h 42m', status: 'OPEN', simulated: true },
  ])
}
export class MockOrderService implements OrderService {
  getPendingOrders = () => wait<Order[]>([
    { id: 'o1', ticket: 'SIM-20412', symbol: 'EURUSD', direction: 'BUY', type: 'Buy Limit', volume: 1, entry: 1.1084, stopLoss: 1.1032, takeProfit: 1.1196, expiry: 'GTC', strategy: 'EMA Pullback', createdAt: '08:12 UTC', status: 'PENDING', simulated: true },
    { id: 'o2', ticket: 'SIM-20411', symbol: 'XAUUSD', direction: 'SELL', type: 'Sell Stop', volume: .4, entry: 2629, stopLoss: 2641, takeProfit: 2602, expiry: 'Today', strategy: 'Gold Scalper', createdAt: '07:58 UTC', status: 'PENDING', simulated: true },
    { id: 'o3', ticket: 'SIM-20408', symbol: 'NAS100', direction: 'BUY', type: 'Buy Stop', volume: .25, entry: 19820, stopLoss: 19720, takeProfit: 20040, expiry: 'GTC', strategy: 'Range Breakout', createdAt: '06:44 UTC', status: 'PENDING', simulated: true },
  ])
  simulateOrder = async (input: SimulationOrderInput) => {
    if (!Number.isFinite(input.volume) || input.volume < .01 || input.volume > 5) throw new Error('Volume must be between 0.01 and 5 lots.')
    if (!Number.isFinite(input.riskPercent) || input.riskPercent <= 0 || input.riskPercent > 2) throw new Error('Risk must be between 0.01% and 2%.')
    return wait({ ticket: `SIM-${Date.now().toString().slice(-6)}`, accepted: true as const, simulated: true as const, input })
  }
}
export class MockRiskService implements RiskService {
  getProfile = () => wait({ riskPerTrade: 1, maxLotSize: 5, maxDailyLoss: 4, maxWeeklyLoss: 8, maxDrawdown: 12, maxOpenPositions: 8, maxOpenRisk: 6, maxTradesPerDay: 20, maxConsecutiveLosses: 4, minMarginLevel: 300, maxSpread: 3, maxSlippage: 1.5, minRiskReward: 1.5 })
  getEvents = () => wait<RiskEvent[]>([{ id: 'r1', severity: 'WARNING', title: 'Position exposure warning', detail: 'USD exposure is at 72% of configured simulation limit.', occurredAt: '08:42 UTC' }, { id: 'r2', severity: 'INFO', title: 'Spread protection checked', detail: 'All monitored spreads within simulation profile.', occurredAt: '08:38 UTC' }])
}
export class MockBacktestService implements BacktestService { run = async (config: BacktestConfig) => { void config; return wait({ netProfit: 18426, returnPercent: 18.43, totalTrades: 284, winningTrades: 181, losingTrades: 103, winRate: 63.73, profitFactor: 1.82, maxDrawdown: 7.4, averageWin: 214, averageLoss: -126, expectancy: 64.88, sharpe: 1.46, recoveryFactor: 2.49 }) } }
export class MockAnalyticsService implements AnalyticsService { getEquitySeries = () => wait(Array.from({ length: 12 }, (_, i) => ({ name: new Date(2026, i, 1).toLocaleString('en', { month: 'short' }), value: 100000 + i * 2100 + Math.sin(i) * 1800 }))) }
export class MockNotificationService implements NotificationService { getNotifications = () => wait<AppNotification[]>([{ id: 'n1', category: 'Signal', title: 'High-confluence simulation signal', body: 'XAUUSD H1 scored 91/100.', time: '2m ago', read: false }, { id: 'n2', category: 'Risk', title: 'Exposure review', body: 'USD exposure reached the warning zone.', time: '18m ago', read: false }, { id: 'n3', category: 'System', title: 'Simulation heartbeat', body: 'All mock services responding.', time: '1h ago', read: true }]) }
export class MockSystemHealthService implements SystemHealthService { getHealth = () => wait<SystemHealth>({ services: [{ name: 'Web App', state: 'ONLINE', detail: 'React + Laravel' }, { name: 'Database', state: 'ONLINE', detail: 'SQLite development' }, { name: 'Trading Engine', state: 'SIMULATION', detail: 'No execution adapter' }, { name: 'MT5 Terminal', state: 'NOT CONNECTED', detail: 'Phase 1 lock' }, { name: 'Broker Connection', state: 'NOT CONNECTED', detail: 'No credentials configured' }, { name: 'Market Data', state: 'MOCK', detail: 'Deterministic fixtures' }, { name: 'Risk Engine', state: 'SIMULATION', detail: 'Client-side demonstration' }, { name: 'Signal Engine', state: 'MOCK', detail: 'No real AI' }, { name: 'Notification Service', state: 'ONLINE', detail: 'In-memory' }], lastHeartbeat: 'Just now', lastMarketUpdate: '4s ago', lastSignal: '2m ago', version: '1.0.0-phase1' }) }
export class MockTradeService implements TradeService { getTrades = () => wait<Trade[]>([{ ticket: 'SIM-10321', symbol: 'GBPUSD', direction: 'BUY', strategy: 'London Breakout', volume: .8, entry: 1.3212, exit: 1.3274, stopLoss: 1.318, takeProfit: 1.328, grossPnl: 496, commission: -5.6, swap: 0, netPnl: 490.4, duration: '3h 14m', closedAt: '2026-09-17 07:42' }]) }
export class MockPaperTradingService implements PaperTradingService {
  getSnapshot = () => wait<PaperTradingSnapshot>({ balance: 50000, equity: 51284.6, profit: 1284.6, positions: [
    { id: 'paper-82', ticket: 'PAPER-082', symbol: 'XAUUSD', direction: 'BUY', strategy: 'Manual Paper', volume: .2, entry: 2638.4, current: 2642.2, stopLoss: 2629, takeProfit: 2661, profit: 76, pips: 38, risk: .5, openedAt: '2h 18m', status: 'OPEN', simulated: true },
    { id: 'paper-79', ticket: 'PAPER-079', symbol: 'EURUSD', direction: 'SELL', strategy: 'Manual Paper', volume: .5, entry: 1.1172, current: 1.11482, stopLoss: 1.121, takeProfit: 1.108, profit: 119, pips: 24, risk: .5, openedAt: '5h 42m', status: 'OPEN', simulated: true },
  ] })
}
export class MockNewsService implements NewsService {
  getEvents = () => wait<NewsEvent[]>([
    { id: 'news-1', time: '08:30', currency: 'USD', impact: 'High', event: 'Core Retail Sales m/m', previous: '0.4%', forecast: '0.3%', actual: '0.5%' },
    { id: 'news-2', time: '09:00', currency: 'EUR', impact: 'Medium', event: 'ECB Economic Bulletin', previous: '—', forecast: '—', actual: '—' },
    { id: 'news-3', time: '12:30', currency: 'USD', impact: 'High', event: 'Initial Jobless Claims', previous: '263K', forecast: '260K', actual: '258K' },
    { id: 'news-4', time: '14:00', currency: 'GBP', impact: 'Low', event: 'MPC Member Speech', previous: '—', forecast: '—', actual: '—' },
    { id: 'news-5', time: '23:30', currency: 'JPY', impact: 'Medium', event: 'National CPI y/y', previous: '2.8%', forecast: '2.9%', actual: '—' },
  ])
}
export class MockAuditService implements AuditService {
  getEvents = () => wait<AuditEvent[]>([
    { id: 'audit-1', timestamp: '08:48:12', user: 'Simulation Admin', action: 'UPDATE', module: 'Risk', description: 'Adjusted max open risk from 5% to 6%', ip: '127.0.0.1', result: 'SUCCESS' },
    { id: 'audit-2', timestamp: '08:42:08', user: 'System', action: 'WARNING', module: 'Risk', description: 'USD exposure crossed warning threshold', ip: 'system', result: 'SUCCESS' },
    { id: 'audit-3', timestamp: '08:31:44', user: 'Simulation Admin', action: 'SIMULATE', module: 'Manual Trading', description: 'Created virtual XAUUSD order SIM-10482', ip: '127.0.0.1', result: 'SUCCESS' },
    { id: 'audit-4', timestamp: '08:21:05', user: 'Mock Signal Engine', action: 'CREATE', module: 'Signals', description: 'Generated simulated XAUUSD signal', ip: 'system', result: 'SUCCESS' },
    { id: 'audit-5', timestamp: '07:58:19', user: 'System', action: 'BLOCK', module: 'Auto Trading', description: 'Rejected unavailable execution request', ip: 'system', result: 'BLOCKED' },
  ])
}

export const services = {
  market: new MockMarketDataService(), account: new MockAccountService(), signals: new MockSignalService(),
  strategies: new MockStrategyService(), positions: new MockPositionService(), orders: new MockOrderService(),
  risk: new MockRiskService(), backtest: new MockBacktestService(), analytics: new MockAnalyticsService(),
  notifications: new MockNotificationService(), health: new MockSystemHealthService(), trades: new MockTradeService(),
  paper: new MockPaperTradingService(), news: new MockNewsService(), audit: new MockAuditService(),
}
