import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { ApiError, setUnauthorizedHandler } from '../api/client'
import { authApi } from '../api/services'
import type { CurrentUser } from '../api/types'
import { AuthContext } from './authState'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser | null>(null)
  const [loading, setLoading] = useState(true)
  const clearSession = useCallback(() => setUser(null), [])

  useEffect(() => {
    setUnauthorizedHandler(clearSession)
    authApi.me()
      .then(setUser)
      .catch((error) => {
        if (!(error instanceof ApiError) || error.status !== 401) console.error('Session check failed', error)
        setUser(null)
      })
      .finally(() => setLoading(false))
    return () => setUnauthorizedHandler(undefined)
  }, [clearSession])

  const login = useCallback(async (email: string, password: string, remember = false) => {
    setUser(await authApi.login(email, password, remember))
  }, [])
  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } finally {
      setUser(null)
    }
  }, [])
  const can = useCallback((permission: string) =>
    !!user?.roles.some((role) => role.permissions?.some((item) => item.name === permission)), [user])
  const value = useMemo(() => ({ user, loading, login, logout, can }), [user, loading, login, logout, can])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
