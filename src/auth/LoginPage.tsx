import { useState, type FormEvent } from 'react'
import { Eye, EyeOff, LockKeyhole, ShieldCheck } from 'lucide-react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { firstValidationError } from '../api/client'
import { authApi } from '../api/services'
import { EnvironmentBadge } from '../components/ui'
import { useAuth } from './authState'

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState(() => localStorage.getItem('nexa.rememberedEmail') ?? '')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(!!localStorage.getItem('nexa.rememberedEmail'))
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [resetMode, setResetMode] = useState(false)
  const [message, setMessage] = useState('')

  if (user) return <Navigate to="/" replace />

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')
    try {
      if (resetMode) {
        const result = await authApi.requestPasswordReset(email)
        setMessage(result.message)
      } else {
        await login(email, password, remember)
        if (remember) localStorage.setItem('nexa.rememberedEmail', email)
        else localStorage.removeItem('nexa.rememberedEmail')
        const destination = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ?? '/'
        navigate(destination, { replace: true })
      }
    } catch (caught) {
      setError(firstValidationError(caught))
    } finally {
      setBusy(false)
    }
  }

  return <main className="login-page">
    <section className="login-visual">
      <div className="login-brand"><span className="brand-mark">N</span><div><strong>NEXA</strong><small>TRADEBOT</small></div></div>
      <div>
        <EnvironmentBadge />
        <h1>Command your strategy.<br /><span>Control every risk.</span></h1>
        <p>A secure simulation workspace for disciplined trading operations.</p>
      </div>
      <div className="login-safety"><ShieldCheck /><p><strong>Simulation-only execution boundary</strong><span>No live broker transmission or real funds.</span></p></div>
    </section>
    <section className="login-form-wrap">
      <form className="login-card" onSubmit={submit}>
        <span className="eyebrow">SECURE ACCESS</span>
        <h2>{resetMode ? 'Reset access' : 'Welcome back'}</h2>
        <p>{resetMode ? 'Register a password reset request. Token delivery is currently backend-dependent.' : 'Sign in to your Nexa TradeBot workspace.'}</p>
        {error && <div className="form-alert error" role="alert">{error}</div>}
        {message && <div className="form-alert success" role="status">{message}</div>}
        <label className="field" htmlFor="login-email"><span>Email address</span><input id="login-email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} required /></label>
        {!resetMode && <label className="field password-field" htmlFor="login-password"><span>Password</span><div><input id="login-password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required /><button type="button" aria-label={showPassword ? 'Hide password' : 'Show password'} onClick={() => setShowPassword(!showPassword)}>{showPassword ? <EyeOff /> : <Eye />}</button></div></label>}
        {!resetMode && <div className="login-options"><label><input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} /> Remember email on this device</label><button type="button" onClick={() => { setResetMode(true); setError(''); setMessage('') }}>Forgot password?</button></div>}
        <button className="login-submit" disabled={busy}><LockKeyhole />{busy ? 'Please wait…' : resetMode ? 'Register reset request' : 'Sign in'}</button>
        {resetMode && <button className="login-back" type="button" onClick={() => { setResetMode(false); setError(''); setMessage('') }}>Back to sign in</button>}
        <small className="login-footnote">Authorized users only · Session protected by server cookies and CSRF validation</small>
      </form>
    </section>
  </main>
}
