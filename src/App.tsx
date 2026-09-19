import { lazy, Suspense } from 'react'
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { LoadingState } from './components/ui'
import { SimulationProvider } from './context/SimulationContext'
import { TradingSourceProvider } from './context/TradingSourceContext'
import { MarketDataStoreProvider } from './context/MarketDataContext'
import { AuthProvider } from './auth/AuthContext'
import { LoginPage } from './auth/LoginPage'
import { ProtectedRoute } from './auth/ProtectedRoute'
import './App.css'

const page = (file: 'TradingPages' | 'OperationsPages', name: string) =>
  lazy(() => import(`./pages/${file}.tsx`).then((module) => ({ default: module[name] })))

const Dashboard = lazy(() => import('./pages/PersistentTradingPages').then((module) => ({ default: module.PersistentDashboard })))
const MarketWatch = lazy(() => import('./pages/PhaseFiveMarketPages').then((module) => ({ default: module.PhaseFiveMarketWatch })))
const MarketScanner = lazy(() => import('./pages/PhaseEightScannerPages').then((module) => ({ default: module.PhaseEightMarketScanner })))
const AISignals = lazy(() => import('./pages/PhaseSevenStrategyPages').then((module) => ({ default: module.PhaseSevenSignals })))
const LiveCharts = lazy(() => import('./pages/PhaseFiveMarketPages').then((module) => ({ default: module.PhaseFiveLiveCharts })))
const Strategies = lazy(() => import('./pages/PhaseSevenStrategyPages').then((module) => ({ default: module.PhaseSevenStrategies })))
const AutoTrading = lazy(() => import('./pages/PhaseFourteenAutomationPages').then((module) => ({ default: module.PhaseFourteenAutomationControlCenter })))
const SystemOperations = lazy(() => import('./pages/PhaseFifteenObservabilityPages').then((module) => ({ default: module.PhaseFifteenOperationsCenter })))
const StrategyGovernance = lazy(() => import('./pages/PhaseSixteenGovernancePages').then((module) => ({ default: module.PhaseSixteenGovernanceCenter })))
const ManualTrading = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentManualTrading })))
const Positions = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentPositions })))
const PendingOrders = lazy(() => import('./pages/PhaseThreeTradingPages').then((module) => ({ default: module.PersistentOrders })))
const TradeHistory = page('OperationsPages', 'TradeHistory')
const RiskManagement = lazy(() => import('./pages/PhaseNineRiskPages').then((module) => ({ default: module.PhaseNineRiskManagement })))
const DemoExecution = lazy(() => import('./pages/PhaseTenExecutionPages').then((module) => ({ default: module.PhaseTenExecutionConsole })))
const TradeManagement = lazy(() => import('./pages/PhaseElevenTradeManagementPages').then((module) => ({ default: module.PhaseElevenTradeManagementDashboard })))
const Backtesting = lazy(() => import('./pages/PhaseTwelveAnalyticsPages').then((module) => ({ default: module.PhaseTwelveBacktestConsole })))
const Analytics = lazy(() => import('./pages/PhaseTwelveAnalyticsPages').then((module) => ({ default: module.PhaseTwelveAnalyticsDashboard })))
const RiskSettings = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentRiskManagement })))
const PaperTrading = page('OperationsPages', 'PaperTrading')
const Reports = page('OperationsPages', 'Reports')
const NewsCalendar = lazy(() => import('./pages/PhaseThirteenIntelligencePages').then((module) => ({ default: module.PhaseThirteenNewsCalendar })))
const AiTradeDesk = lazy(() => import('./pages/PhaseThirteenIntelligencePages').then((module) => ({ default: module.PhaseThirteenIntelligenceDesk })))
const AdvancedIntelligence = lazy(() => import('./pages/PhaseSeventeenIntelligencePages').then((module) => ({ default: module.PhaseSeventeenAdvancedDesk })))
const PortfolioCommandCenter = lazy(() => import('./pages/PhaseEighteenFleetPages').then((module) => ({ default: module.PhaseEighteenFleetPages })))
const MT5Accounts = lazy(() => import('./pages/PhaseFourMt5Pages').then((module) => ({ default: module.Mt5AccountsPage })))
const Mt5Dashboard = lazy(() => import('./pages/PhaseFourMt5Pages').then((module) => ({ default: module.Mt5Dashboard })))
const Mt5Market = lazy(() => import('./pages/PhaseFourMt5Pages').then((module) => ({ default: module.Mt5MarketWatch })))
const Mt5Charts = lazy(() => import('./pages/PhaseFourMt5Pages').then((module) => ({ default: module.Mt5LiveCharts })))
const Mt5ReadModels = lazy(() => import('./pages/PhaseFourMt5Pages').then((module) => ({ default: module.Mt5ReadModels })))
const Mt5Reconciliation = lazy(() => import('./pages/PhaseFourMt5Pages').then((module) => ({ default: module.Mt5ReconciliationPage })))
const Notifications = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentNotifications })))
const SystemHealth = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentSystemHealth })))
const AuditLogs = lazy(() => import('./pages/PersistentOperationsPages').then((module) => ({ default: module.PersistentAuditLogs })))
const Settings = lazy(() => import('./pages/SettingsPage').then((module) => ({ default: module.PersistentSettings })))

function ProtectedApp() {
  return <TradingSourceProvider><MarketDataStoreProvider><SimulationProvider><AppShell /></SimulationProvider></MarketDataStoreProvider></TradingSourceProvider>
}

export default function App() {
  return <HashRouter><AuthProvider><Suspense fallback={<LoadingState />}><Routes>
    <Route path="/login" element={<LoginPage />} />
    <Route element={<ProtectedRoute />}><Route element={<ProtectedApp />}>
    <Route index element={<Dashboard />} /><Route path="market-watch" element={<MarketWatch />} /><Route path="market-scanner" element={<MarketScanner />} />
    <Route path="ai-signals" element={<AISignals />} /><Route path="live-charts" element={<LiveCharts />} /><Route path="strategies" element={<Strategies />} />
    <Route path="auto-trading" element={<AutoTrading />} /><Route path="manual-trading" element={<ManualTrading />} /><Route path="open-positions" element={<Positions />} />
    <Route path="pending-orders" element={<PendingOrders />} /><Route path="trade-history" element={<TradeHistory />} />    <Route path="risk-management" element={<RiskManagement />} />
    <Route path="risk-settings" element={<RiskSettings />} />
    <Route path="demo-execution" element={<DemoExecution />} />
    <Route path="trade-management" element={<TradeManagement />} />
    <Route path="backtesting" element={<Backtesting />} /><Route path="paper-trading" element={<PaperTrading />} /><Route path="analytics" element={<Analytics />} />
    <Route path="reports" element={<Reports />} /><Route path="news-calendar" element={<NewsCalendar />} /><Route path="ai-trade-desk" element={<AiTradeDesk />} /><Route path="advanced-intelligence" element={<AdvancedIntelligence />} /><Route path="portfolio-command-center" element={<PortfolioCommandCenter />} /><Route path="broker-connections" element={<PortfolioCommandCenter />} /><Route path="account-wizard" element={<PortfolioCommandCenter />} /><Route path="mt5-accounts" element={<MT5Accounts />} />
    <Route path="mt5-dashboard" element={<Mt5Dashboard />} /><Route path="mt5-market" element={<Mt5Market />} /><Route path="mt5-charts" element={<Mt5Charts />} />
    <Route path="mt5-read-models" element={<Mt5ReadModels />} /><Route path="mt5-reconciliation" element={<Mt5Reconciliation />} />
    <Route path="notifications" element={<Notifications />} /><Route path="system-health" element={<SystemHealth />} /><Route path="system-operations" element={<SystemOperations />} /><Route path="validation-lab" element={<SystemOperations />} /><Route path="alert-center" element={<SystemOperations />} /><Route path="strategy-governance" element={<StrategyGovernance />} /><Route path="strategy-lab" element={<StrategyGovernance />} /><Route path="audit-logs" element={<AuditLogs />} />
    <Route path="settings" element={<Settings />} />
    </Route></Route>
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes></Suspense></AuthProvider></HashRouter>
}
