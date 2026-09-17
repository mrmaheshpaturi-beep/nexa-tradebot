import { lazy, Suspense } from 'react'
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { LoadingState } from './components/ui'
import { SimulationProvider } from './context/SimulationContext'
import { AuthProvider } from './auth/AuthContext'
import { LoginPage } from './auth/LoginPage'
import { ProtectedRoute } from './auth/ProtectedRoute'
import './App.css'

const page = (file: 'TradingPages' | 'OperationsPages', name: string) =>
  lazy(() => import(`./pages/${file}.tsx`).then((module) => ({ default: module[name] })))

const Dashboard = lazy(() => import('./pages/PersistentTradingPages').then((module) => ({ default: module.PersistentDashboard })))
const MarketWatch = page('TradingPages', 'MarketWatch')
const MarketScanner = page('TradingPages', 'MarketScanner')
const AISignals = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentSignals })))
const LiveCharts = page('TradingPages', 'LiveCharts')
const Strategies = lazy(() => import('./pages/PersistentTradingPages').then((module) => ({ default: module.PersistentStrategies })))
const AutoTrading = page('TradingPages', 'AutoTrading')
const ManualTrading = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentManualTrading })))
const Positions = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentPositions })))
const Backtesting = page('TradingPages', 'Backtesting')
const PendingOrders = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentOrders })))
const TradeHistory = page('OperationsPages', 'TradeHistory')
const RiskManagement = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentRiskManagement })))
const PaperTrading = page('OperationsPages', 'PaperTrading')
const Analytics = page('OperationsPages', 'Analytics')
const Reports = page('OperationsPages', 'Reports')
const NewsCalendar = page('OperationsPages', 'NewsCalendar')
const MT5Accounts = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentAccounts })))
const Notifications = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentNotifications })))
const SystemHealth = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentSystemHealth })))
const AuditLogs = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentAuditLogs })))
const Settings = lazy(() => import('./pages/SettingsPage').then((module) => ({ default: module.PersistentSettings })))

function ProtectedApp() {
  return <SimulationProvider><AppShell /></SimulationProvider>
}

export default function App() {
  return <HashRouter><AuthProvider><Suspense fallback={<LoadingState />}><Routes>
    <Route path="/login" element={<LoginPage />} />
    <Route element={<ProtectedRoute />}><Route element={<ProtectedApp />}>
    <Route index element={<Dashboard />} /><Route path="market-watch" element={<MarketWatch />} /><Route path="market-scanner" element={<MarketScanner />} />
    <Route path="ai-signals" element={<AISignals />} /><Route path="live-charts" element={<LiveCharts />} /><Route path="strategies" element={<Strategies />} />
    <Route path="auto-trading" element={<AutoTrading />} /><Route path="manual-trading" element={<ManualTrading />} /><Route path="open-positions" element={<Positions />} />
    <Route path="pending-orders" element={<PendingOrders />} /><Route path="trade-history" element={<TradeHistory />} /><Route path="risk-management" element={<RiskManagement />} />
    <Route path="backtesting" element={<Backtesting />} /><Route path="paper-trading" element={<PaperTrading />} /><Route path="analytics" element={<Analytics />} />
    <Route path="reports" element={<Reports />} /><Route path="news-calendar" element={<NewsCalendar />} /><Route path="mt5-accounts" element={<MT5Accounts />} />
    <Route path="notifications" element={<Notifications />} /><Route path="system-health" element={<SystemHealth />} /><Route path="audit-logs" element={<AuditLogs />} />
    <Route path="settings" element={<Settings />} />
    </Route></Route>
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes></Suspense></AuthProvider></HashRouter>
}
