import { useEffect, useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Activity, BarChart3, Bell, Bot, BriefcaseBusiness, CalendarDays, CandlestickChart, ChevronLeft, CircleDollarSign, ClipboardList, Command, FileBarChart, Gauge, HeartPulse, History, LayoutDashboard, LogOut, Menu, PanelLeftClose, ScanSearch, Search, Settings, ShieldAlert, SlidersHorizontal, Sparkles, Target, UserCircle } from 'lucide-react'
import { mt5Api, phaseTwoApi } from '../api/services'
import { EnvironmentBadge } from './ui'
import { useAuth } from '../auth/authState'
import { useTradingSource } from '../context/tradingSourceState'

const navigation = [
  ['Dashboard', '/', LayoutDashboard], ['Market Watch', '/market-watch', CandlestickChart], ['Market Scanner', '/market-scanner', ScanSearch],
  ['AI Signals', '/ai-signals', Sparkles], ['Live Charts', '/live-charts', BarChart3], ['Strategies', '/strategies', Bot],
  ['Auto Trading', '/auto-trading', SlidersHorizontal], ['Manual Trading', '/manual-trading', CircleDollarSign], ['Open Positions', '/open-positions', BriefcaseBusiness],
  ['Pending Orders', '/pending-orders', ClipboardList], ['Trade History', '/trade-history', History], ['Risk Management', '/risk-management', ShieldAlert],
  ['Backtesting', '/backtesting', Target], ['Paper Trading', '/paper-trading', Activity], ['Analytics', '/analytics', Gauge],
  ['Reports', '/reports', FileBarChart], ['News Calendar', '/news-calendar', CalendarDays], ['MT5 Accounts', '/mt5-accounts', Command],
  ['MT5 Dashboard', '/mt5-dashboard', LayoutDashboard], ['MT5 Market', '/mt5-market', CandlestickChart], ['MT5 Charts', '/mt5-charts', BarChart3],
  ['MT5 Read Models', '/mt5-read-models', ClipboardList], ['MT5 Reconciliation', '/mt5-reconciliation', ShieldAlert],
  ['Notifications', '/notifications', Bell], ['System Health', '/system-health', HeartPulse], ['Audit Logs', '/audit-logs', ClipboardList], ['Settings', '/settings', Settings],
] as const

export function AppShell() {
  const { user, logout, can } = useAuth()
  const { source, setSource } = useTradingSource()
  const [collapsed, setCollapsed] = useState(user?.preference?.sidebar_collapsed ?? false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [connectionLabel, setConnectionLabel] = useState('Simulation services')
  useEffect(() => {
    if (source === 'MT5_DEMO' && can('mt5.read')) {
      mt5Api.status().then((status) => {
        setConnectionLabel(status.configured ? `MT5 bridge ${status.circuit_state}` : 'MT5 bridge not configured')
      }).catch(() => setConnectionLabel('MT5 bridge unavailable'))
      return
    }
    phaseTwoApi.status().then((status) => {
      setConnectionLabel(status.mt5_bridge?.configured ? `Simulation active · bridge ${status.mt5_bridge.state}` : 'Mock market data')
    }).catch(() => setConnectionLabel('Simulation services'))
  }, [source, can])
  const visibleNavigation = navigation.filter(([label]) => {
    if (label === 'Audit Logs') return can('audit_logs.view')
    if (label === 'Strategies') return can('strategies.view')
    if (label === 'Risk Management') return can('risk_profiles.view')
    if (label === 'MT5 Accounts' || label.startsWith('MT5 ')) return can('mt5.read')
    if (label === 'Notifications') return can('notifications.view')
    return true
  })
  return <div className={`app ${collapsed ? 'collapsed' : ''}`}>
    <aside className={mobileOpen ? 'mobile-open' : ''}>
      <div className="brand"><div className="brand-mark">N</div><div><strong>NEXA</strong><span>TRADEBOT</span></div><button aria-label="Close navigation" onClick={() => setMobileOpen(false)} className="mobile-close"><ChevronLeft /></button></div>
      <nav aria-label="Primary navigation">{visibleNavigation.map(([label, path, Icon]) => <NavLink key={path} to={path} end={path === '/'} title={label} onClick={() => setMobileOpen(false)}><Icon size={18} /><span>{label}</span></NavLink>)}</nav>
      <div className="sidebar-foot"><button onClick={() => setCollapsed(!collapsed)} aria-label="Collapse sidebar"><PanelLeftClose size={18} /><span>Collapse menu</span></button><div><span className="status-dot" /><p><strong>System operational</strong><small>{connectionLabel}</small></p></div></div>
    </aside>
    <div className="workspace">
      <header className="topbar">
        <button className="menu-button" aria-label="Open navigation" onClick={() => setMobileOpen(true)}><Menu /></button>
        <div className="terminal-title"><strong>Nexa TradeBot</strong><EnvironmentBadge /></div>
        <label className="global-search" htmlFor="global-search"><Search size={16} /><input id="global-search" name="global-search" aria-label="Global search" placeholder="Search symbol, strategy, ticket…" /></label>
        <div className="top-actions">
          {can('mt5.read') && <label className="source-selector"><span>Source</span><select value={source} onChange={(event) => setSource(event.target.value as 'SIMULATION' | 'MT5_DEMO')} aria-label="Trading source"><option value="SIMULATION">SIMULATION</option><option value="MT5_DEMO">MT5 DEMO READ-ONLY</option></select></label>}
          <span className="connection"><span className="status-dot" /> {connectionLabel}</span>
          {can('notifications.view') && <NavLink to="/notifications" aria-label="Notifications" className="icon-button"><Bell size={18} /></NavLink>}<div className="profile"><span>{user?.name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase()}</span><p><strong>{user?.name}</strong><small>{user?.roles[0]?.label ?? user?.roles[0]?.name}</small></p><UserCircle size={17} /></div><button className="icon-button" aria-label="Sign out" title="Sign out" onClick={logout}><LogOut size={16} /></button></div>
      </header>
      <div className="safety-strip"><ShieldAlert size={14} /> {source === 'MT5_DEMO'
        ? 'MT5 DEMO read-only source — external observations only. No broker execution, order placement, or silent mock fallback.'
        : 'Simulation environment — lifecycle mutations are available only here. No broker connection, live execution, or real funds.'}</div>
      <main><Outlet /></main>
    </div>
  </div>
}
