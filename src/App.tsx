import { lazy, Suspense } from 'react'
import { HashRouter, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { LoadingState } from './components/ui'
import './App.css'

const page = (file: 'TradingPages' | 'OperationsPages', name: string) =>
  lazy(() => import(`./pages/${file}.tsx`).then((module) => ({ default: module[name] })))

const Dashboard = page('TradingPages', 'Dashboard')
const MarketWatch = page('TradingPages', 'MarketWatch')
const MarketScanner = page('TradingPages', 'MarketScanner')
const AISignals = page('TradingPages', 'AISignals')
const LiveCharts = page('TradingPages', 'LiveCharts')
const Strategies = page('TradingPages', 'Strategies')
const AutoTrading = page('TradingPages', 'AutoTrading')
const ManualTrading = page('TradingPages', 'ManualTrading')
const Positions = page('TradingPages', 'Positions')
const Backtesting = page('TradingPages', 'Backtesting')
const PendingOrders = page('OperationsPages', 'PendingOrders')
const TradeHistory = page('OperationsPages', 'TradeHistory')
const RiskManagement = page('OperationsPages', 'RiskManagement')
const PaperTrading = page('OperationsPages', 'PaperTrading')
const Analytics = page('OperationsPages', 'Analytics')
const Reports = page('OperationsPages', 'Reports')
const NewsCalendar = page('OperationsPages', 'NewsCalendar')
const MT5Accounts = page('OperationsPages', 'MT5Accounts')
const Notifications = page('OperationsPages', 'Notifications')
const SystemHealth = page('OperationsPages', 'SystemHealthPage')
const AuditLogs = page('OperationsPages', 'AuditLogs')
const Settings = page('OperationsPages', 'Settings')

export default function App() {
  return <HashRouter><Suspense fallback={<LoadingState />}><Routes><Route element={<AppShell />}>
    <Route index element={<Dashboard />} /><Route path="market-watch" element={<MarketWatch />} /><Route path="market-scanner" element={<MarketScanner />} />
    <Route path="ai-signals" element={<AISignals />} /><Route path="live-charts" element={<LiveCharts />} /><Route path="strategies" element={<Strategies />} />
    <Route path="auto-trading" element={<AutoTrading />} /><Route path="manual-trading" element={<ManualTrading />} /><Route path="open-positions" element={<Positions />} />
    <Route path="pending-orders" element={<PendingOrders />} /><Route path="trade-history" element={<TradeHistory />} /><Route path="risk-management" element={<RiskManagement />} />
    <Route path="backtesting" element={<Backtesting />} /><Route path="paper-trading" element={<PaperTrading />} /><Route path="analytics" element={<Analytics />} />
    <Route path="reports" element={<Reports />} /><Route path="news-calendar" element={<NewsCalendar />} /><Route path="mt5-accounts" element={<MT5Accounts />} />
    <Route path="notifications" element={<Notifications />} /><Route path="system-health" element={<SystemHealth />} /><Route path="audit-logs" element={<AuditLogs />} />
    <Route path="settings" element={<Settings />} />
  </Route></Routes></Suspense></HashRouter>
}
