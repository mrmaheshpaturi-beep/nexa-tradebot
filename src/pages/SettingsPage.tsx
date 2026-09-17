import { useCallback, useMemo, useState, type FormEvent } from 'react'
import { Check, Pencil, Plus, UserCog } from 'lucide-react'
import { firstValidationError } from '../api/client'
import { phaseTwoApi } from '../api/services'
import type { Preference, UserRecord } from '../api/types'
import { useAuth } from '../auth/authState'
import { ConfirmationDialog, DataTable, ErrorState, FilterBar, LoadingState, PageHeader, Panel, StatusBadge } from '../components/ui'
import { useService } from '../hooks/useService'

const roles = ['SUPER_ADMIN', 'ADMIN', 'TRADER', 'ANALYST', 'VIEWER']

export function PersistentSettings() {
  const { can } = useAuth()
  const tabs = ['General', 'Preferences', ...(can('users.view') ? ['Users'] : [])]
  const [tab, setTab] = useState('General')
  return <>
    <PageHeader title="Settings" description="Persisted application configuration, personal preferences, and authorized user administration." />
    <div className="settings-layout">
      <nav>{tabs.map((item) => <button key={item} className={tab === item ? 'active' : ''} onClick={() => setTab(item)}>{item}</button>)}</nav>
      {tab === 'General' ? <GeneralSettings /> : tab === 'Users' ? <UsersSettings /> : <PreferenceSettings />}
    </div>
  </>
}

function GeneralSettings() {
  const result = useService(useCallback(() => phaseTwoApi.settings(), []))
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Settings are unavailable.'} />
  return <GeneralSettingsForm settings={result.data.filter((item) => item.group === 'general')} />
}

function GeneralSettingsForm({ settings }: { settings: Awaited<ReturnType<typeof phaseTwoApi.settings>> }) {
  const { can } = useAuth()
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(settings.map((item) => [item.key, String(item.value ?? '')])))
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const save = async () => {
    setBusy(true); setError(''); setMessage('')
    try {
      await Promise.all(Object.entries(values).map(([key, value]) => phaseTwoApi.updateSetting(key, value, settings.find((item) => item.key === key)?.is_public)))
      setMessage('General settings saved.')
    } catch (caught) { setError(firstValidationError(caught)) } finally { setBusy(false) }
  }
  return <Panel title="General Settings" subtitle="Database-backed application values">
    <div className="settings-form">{Object.entries(values).map(([key, value]) => <label className="setting-row" key={key}><p><strong>{key.replaceAll('_', ' ')}</strong><span>Application setting</span></p><input value={value} disabled={!can('settings.update')} onChange={(e) => setValues((current) => ({ ...current, [key]: e.target.value }))} /></label>)}</div>
    {error && <p className="form-alert error">{error}</p>}{message && <p className="form-alert success">{message}</p>}
    <button className="btn primary" disabled={!can('settings.update') || busy} onClick={save}><Check />{busy ? 'Saving…' : 'Save general settings'}</button>
  </Panel>
}

function PreferenceSettings() {
  const result = useService(useCallback(() => phaseTwoApi.preference(), []))
  if (result.error) return <ErrorState message={result.error} />
  if (result.loading || !result.data) return <LoadingState />
  return <PreferenceSettingsForm initial={result.data} />
}

function PreferenceSettingsForm({ initial }: { initial: Preference }) {
  const { can } = useAuth()
  const [form, setForm] = useState(initial)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const update = <K extends keyof Preference>(key: K, value: Preference[K]) => setForm((current) => current ? { ...current, [key]: value } : current)
  const save = async () => {
    setError(''); setMessage('')
    try {
      setForm(await phaseTwoApi.updatePreference({
        timezone: form.timezone, locale: form.locale, theme: form.theme, sidebar_collapsed: form.sidebar_collapsed,
        default_dashboard: form.default_dashboard, favorite_symbols: form.favorite_symbols ?? [], default_timeframe: form.default_timeframe,
        table_page_size: form.table_page_size, notifications_enabled: form.notifications_enabled,
      }))
      setMessage('Preferences saved.')
    } catch (caught) { setError(firstValidationError(caught)) }
  }
  return <Panel title="Personal Preferences" subtitle="Stored for the signed-in user">
    <div className="form-grid">
      <label className="field"><span>Timezone</span><input value={form.timezone} onChange={(e) => update('timezone', e.target.value)} /></label>
      <label className="field"><span>Theme</span><select value={form.theme} onChange={(e) => update('theme', e.target.value as Preference['theme'])}><option value="dark">Dark</option><option value="light">Light</option><option value="system">System</option></select></label>
      <label className="field"><span>Default dashboard</span><select value={form.default_dashboard} onChange={(e) => update('default_dashboard', e.target.value as Preference['default_dashboard'])}><option value="overview">Overview</option><option value="trading">Trading</option><option value="risk">Risk</option></select></label>
      <label className="field"><span>Default timeframe</span><select value={form.default_timeframe} onChange={(e) => update('default_timeframe', e.target.value)}>{['M1','M5','M15','M30','H1','H4','D1'].map((item) => <option key={item}>{item}</option>)}</select></label>
      <label className="field"><span>Table page size</span><select value={form.table_page_size} onChange={(e) => update('table_page_size', Number(e.target.value) as Preference['table_page_size'])}>{[10,25,50,100].map((item) => <option key={item}>{item}</option>)}</select></label>
      <label className="check-row"><input type="checkbox" checked={form.sidebar_collapsed} onChange={(e) => update('sidebar_collapsed', e.target.checked)} /> Collapse sidebar by default</label>
      <label className="check-row"><input type="checkbox" checked={form.notifications_enabled} onChange={(e) => update('notifications_enabled', e.target.checked)} /> Enable notifications</label>
    </div>
    {error && <p className="form-alert error">{error}</p>}{message && <p className="form-alert success">{message}</p>}
    <button className="btn primary" disabled={!can('preferences.update')} onClick={save}>Save preferences</button>
  </Panel>
}

function UsersSettings() {
  const { can, user: currentUser } = useAuth()
  const result = useService(useCallback(() => phaseTwoApi.users(), []))
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('ALL')
  const [editing, setEditing] = useState<UserRecord | 'new' | null>(null)
  const [statusAction, setStatusAction] = useState<{ user: UserRecord; action: 'activate' | 'suspend' | 'disable' }>()
  const filtered = useMemo(() => result.data?.data.filter((user) =>
    (status === 'ALL' || user.status === status) && `${user.name} ${user.email}`.toLowerCase().includes(search.toLowerCase())) ?? [], [result.data, search, status])
  if (result.loading) return <LoadingState />
  if (result.error || !result.data) return <ErrorState message={result.error ?? 'Users are unavailable.'} />
  const applyStatus = async () => {
    if (!statusAction) return
    await phaseTwoApi.setUserStatus(statusAction.user.id, statusAction.action)
    setStatusAction(undefined)
    result.reload()
  }
  return <div>
    <Panel title="User Administration" subtitle={`${result.data.total} users`} actions={can('users.create') ? <button className="btn primary" onClick={() => setEditing('new')}><Plus />Create user</button> : undefined}>
      <FilterBar search={search} searchId="user-search" onSearch={setSearch}>
        <select aria-label="Filter user status" value={status} onChange={(e) => setStatus(e.target.value)}><option>ALL</option><option>ACTIVE</option><option>SUSPENDED</option><option>DISABLED</option></select>
      </FilterBar>
      <DataTable columns={['Name', 'Email', 'Role', 'Status', 'Last login', 'Actions']} rows={filtered.map((user) => [
        <strong key={user.id}>{user.name}</strong>, user.email, user.roles.map((role) => role.label ?? role.name).join(', '),
        <StatusBadge key={`${user.id}-status`} tone={user.status === 'ACTIVE' ? 'good' : user.status === 'SUSPENDED' ? 'warning' : 'bad'}>{user.status}</StatusBadge>,
        user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never',
        <div className="row-actions" key={`${user.id}-actions`}>
          {can('users.update') && <button onClick={() => setEditing(user)}><Pencil /> Edit</button>}
          {can('users.status') && user.id !== currentUser?.id && user.status !== 'ACTIVE' && <button onClick={() => setStatusAction({ user, action: 'activate' })}>Activate</button>}
          {can('users.status') && user.id !== currentUser?.id && user.status === 'ACTIVE' && <button onClick={() => setStatusAction({ user, action: 'suspend' })}>Suspend</button>}
          {can('users.status') && user.id !== currentUser?.id && user.status !== 'DISABLED' && <button onClick={() => setStatusAction({ user, action: 'disable' })}>Disable</button>}
        </div>,
      ])} />
    </Panel>
    {editing && <UserEditor user={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); result.reload() }} />}
    <ConfirmationDialog open={!!statusAction} title={`${statusAction?.action} user`} onCancel={() => setStatusAction(undefined)} onConfirm={applyStatus}>
      Confirm {statusAction?.action} for {statusAction?.user.email}. This changes server-enforced account access.
    </ConfirmationDialog>
  </div>
}

function UserEditor({ user, onClose, onSaved }: { user: UserRecord | 'new'; onClose: () => void; onSaved: () => void }) {
  const isNew = user === 'new'
  const [name, setName] = useState(isNew ? '' : user.name)
  const [email, setEmail] = useState(isNew ? '' : user.email)
  const [role, setRole] = useState(isNew ? 'VIEWER' : user.roles[0]?.name ?? 'VIEWER')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const save = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError('')
    try {
      if (isNew) await phaseTwoApi.createUser({ name, email, role, password })
      else await phaseTwoApi.updateUser(user.id, { name, email, role, ...(password ? { password } : {}) })
      onSaved()
    } catch (caught) { setError(firstValidationError(caught)) } finally { setBusy(false) }
  }
  return <div className="drawer"><button className="drawer-close" onClick={onClose}>×</button><span className="eyebrow">USER ADMINISTRATION</span><h2>{isNew ? 'Create user' : 'Edit user'}</h2>
    <form onSubmit={save} className="drawer-form">
      <label className="field"><span>Name</span><input value={name} onChange={(e) => setName(e.target.value)} required /></label>
      <label className="field"><span>Email</span><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required /></label>
      <label className="field"><span>Role</span><select value={role} onChange={(e) => setRole(e.target.value)}>{roles.map((item) => <option key={item}>{item}</option>)}</select></label>
      <label className="field"><span>{isNew ? 'Password' : 'New password (optional)'}</span><input type="password" minLength={12} value={password} onChange={(e) => setPassword(e.target.value)} required={isNew} /></label>
      {error && <p className="form-alert error">{error}</p>}
      <button className="btn primary" disabled={busy}><UserCog />{busy ? 'Saving…' : 'Save user'}</button>
    </form>
  </div>
}
