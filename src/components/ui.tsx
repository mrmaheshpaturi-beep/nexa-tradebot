import type { ReactNode } from 'react'
import { AlertTriangle, ChevronDown, LoaderCircle, Search } from 'lucide-react'
import { useTradingSource } from '../context/tradingSourceState'

export function PageHeader({ title, description, actions }: { title: string; description: string; actions?: ReactNode }) {
  const { source } = useTradingSource()
  const phase = source === 'MT5_DEMO' ? 'PHASE 4 / MT5 DEMO READ-ONLY' : 'PHASE 7 / STRATEGY ENGINE'
  return <header className="page-header"><div><div className="eyebrow">NEXA TRADEBOT / {phase}</div><h1>{title}</h1><p>{description}</p></div>{actions && <div className="header-actions">{actions}</div>}</header>
}
export function EnvironmentBadge() {
  const { source } = useTradingSource()
  if (source === 'MT5_DEMO') return <span className="badge info"><span className="pulse-dot" /> MT5 DEMO READ-ONLY</span>
  return <span className="badge simulation"><span className="pulse-dot" /> SIMULATION</span>
}
export function StatusBadge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'good' | 'bad' | 'warning' | 'info' | 'purple' | 'neutral' }) { return <span className={`badge ${tone}`}>{children}</span> }
export function DirectionBadge({ value }: { value: string }) { return <StatusBadge tone={value === 'BUY' ? 'good' : value === 'SELL' ? 'bad' : 'neutral'}>{value}</StatusBadge> }
export function PnLDisplay({ value }: { value: number }) { return <span className={value >= 0 ? 'positive' : 'negative'}>{value >= 0 ? '+' : ''}${Math.abs(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}</span> }
export function MetricCard({ label, value, detail, tone }: { label: string; value: ReactNode; detail?: string; tone?: string }) { return <article className={`metric-card ${tone ?? ''}`}><span>{label}</span><strong>{value}</strong>{detail && <small>{detail}</small>}</article> }
export function Panel({ title, subtitle, actions, children, className = '' }: { title: string; subtitle?: string; actions?: ReactNode; children: ReactNode; className?: string }) { return <section className={`panel ${className}`}><div className="panel-head"><div><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</div>{actions}</div>{children}</section> }
export function FilterBar({ search, searchId = 'filter-search', onSearch, children }: { search?: string; searchId?: string; onSearch?: (value: string) => void; children?: ReactNode }) {
  return <div className="filter-bar">{onSearch && <label className="search-input" htmlFor={searchId}><Search size={15} /><input id={searchId} name={searchId} aria-label="Search" value={search} onChange={(e) => onSearch(e.target.value)} placeholder="Search…" /></label>}{children}<button className="select-btn" type="button">Last updated: now <ChevronDown size={14} /></button></div>
}
export function DataTable({ columns, rows }: { columns: string[]; rows: ReactNode[][] }) {
  if (!rows.length) return <EmptyState title="No records found" detail="No persisted records match the current view." />
  return <div className="table-wrap"><table><thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead><tbody>{rows.map((row, index) => <tr key={index}>{row.map((cell, i) => <td key={i}>{cell}</td>)}</tr>)}</tbody></table></div>
}
export function LoadingState() { return <div className="state"><LoaderCircle className="spin" /><strong>Loading workspace</strong><span>Retrieving authorized data…</span></div> }
export function ErrorState({ message }: { message: string }) { return <div className="state error"><AlertTriangle /><strong>Unable to load module</strong><span>{message}</span></div> }
export function EmptyState({ title, detail }: { title: string; detail: string }) { return <div className="state"><strong>{title}</strong><span>{detail}</span></div> }
export function RiskGauge({ label, value, limit, inverse = false }: { label: string; value: number; limit: number; inverse?: boolean }) {
  const pct = Math.min(100, inverse ? Math.max(0, 100 - value / limit * 100) : value / limit * 100)
  return <div className="gauge"><div><span>{label}</span><strong>{value}%</strong></div><div className="gauge-track"><i style={{ width: `${pct}%` }} /></div><small>{inverse ? `Minimum ${limit}%` : `${pct.toFixed(0)}% of limit`}</small></div>
}
export function AIScoreGauge({ score }: { score: number }) { const label = score >= 85 ? 'High Confluence' : score >= 70 ? 'Strong Setup' : score >= 50 ? 'Watch' : 'Weak'; return <div className="ai-score" style={{ '--score': `${score * 3.6}deg` } as React.CSSProperties}><div><strong>{score}</strong><span>{label}</span></div></div> }
export function ConfirmationDialog({ open, title, children, onCancel, onConfirm }: { open: boolean; title: string; children: ReactNode; onCancel: () => void; onConfirm: () => void }) {
  if (!open) return null
  return <div className="dialog-backdrop" role="presentation"><div className="dialog" role="dialog" aria-modal="true" aria-labelledby="dialog-title"><h2 id="dialog-title">{title}</h2><p>{children}</p><div><button className="btn ghost" onClick={onCancel}>Cancel</button><button className="btn danger" onClick={onConfirm}>Confirm simulation action</button></div></div></div>
}
