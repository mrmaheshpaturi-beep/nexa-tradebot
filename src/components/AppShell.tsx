import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Activity, BarChart3, Bell, Bot, BriefcaseBusiness, CalendarDays, CandlestickChart, ChevronLeft, CircleDollarSign, ClipboardList, Command, FileBarChart, Gauge, HeartPulse, History, LayoutDashboard, Menu, PanelLeftClose, ScanSearch, Search, Settings, ShieldAlert, SlidersHorizontal, Sparkles, Target, UserCircle } from 'lucide-react'
import { EnvironmentBadge } from './ui'

const navigation = [
  ['Dashboard', '/', LayoutDashboard], ['Market Watch', '/market-watch', CandlestickChart], ['Market Scanner', '/market-scanner', ScanSearch],
  ['AI Signals', '/ai-signals', Sparkles], ['Live Charts', '/live-charts', BarChart3], ['Strategies', '/strategies', Bot],
  ['Auto Trading', '/auto-trading', SlidersHorizontal], ['Manual Trading', '/manual-trading', CircleDollarSign], ['Open Positions', '/open-positions', BriefcaseBusiness],
  ['Pending Orders', '/pending-orders', ClipboardList], ['Trade History', '/trade-history', History], ['Risk Management', '/risk-management', ShieldAlert],
  ['Backtesting', '/backtesting', Target], ['Paper Trading', '/paper-trading', Activity], ['Analytics', '/analytics', Gauge],
  ['Reports', '/reports', FileBarChart], ['News Calendar', '/news-calendar', CalendarDays], ['MT5 Accounts', '/mt5-accounts', Command],
  ['Notifications', '/notifications', Bell], ['System Health', '/system-health', HeartPulse], ['Audit Logs', '/audit-logs', ClipboardList], ['Settings', '/settings', Settings],
] as const

export function AppShell() {
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  return <div className={`app ${collapsed ? 'collapsed' : ''}`}>
    <aside className={mobileOpen ? 'mobile-open' : ''}>
      <div className="brand"><div className="brand-mark">N</div><div><strong>NEXA</strong><span>TRADEBOT</span></div><button aria-label="Close navigation" onClick={() => setMobileOpen(false)} className="mobile-close"><ChevronLeft /></button></div>
      <nav aria-label="Primary navigation">{navigation.map(([label, path, Icon]) => <NavLink key={path} to={path} end={path === '/'} title={label} onClick={() => setMobileOpen(false)}><Icon size={18} /><span>{label}</span></NavLink>)}</nav>
      <div className="sidebar-foot"><button onClick={() => setCollapsed(!collapsed)} aria-label="Collapse sidebar"><PanelLeftClose size={18} /><span>Collapse menu</span></button><div><span className="status-dot" /><p><strong>System operational</strong><small>Simulation services</small></p></div></div>
    </aside>
    <div className="workspace">
      <header className="topbar">
        <button className="menu-button" aria-label="Open navigation" onClick={() => setMobileOpen(true)}><Menu /></button>
        <div className="terminal-title"><strong>Nexa TradeBot</strong><EnvironmentBadge /></div>
        <label className="global-search" htmlFor="global-search"><Search size={16} /><input id="global-search" name="global-search" aria-label="Global search" placeholder="Search symbol, strategy, ticket…" /></label>
        <div className="top-actions"><span className="connection"><span className="status-dot" /> Mock data connected</span><button aria-label="Notifications" className="icon-button"><Bell size={18} /><i>2</i></button><button className="profile"><span>MP</span><p><strong>Mahesh P.</strong><small>Administrator</small></p><UserCircle size={17} /></button></div>
      </header>
      <div className="safety-strip"><ShieldAlert size={14} /> Simulation environment — no broker connection, live execution, or real funds are available.</div>
      <main><Outlet /></main>
    </div>
  </div>
}
