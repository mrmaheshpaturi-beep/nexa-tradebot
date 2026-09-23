import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { mt5Api } from '../api/services'
import { TradingSourceContext, type TradingSource } from './tradingSourceState'

const STORAGE_KEY = 'nexa.trading-source'

export function TradingSourceProvider({ children }: { children: ReactNode }) {
  const [source, setSourceState] = useState<TradingSource>(() => {
    const stored = localStorage.getItem(STORAGE_KEY)
    // MT5 DEMO is the safe, read-only default for this connected application.
    // Simulation remains available only when a user explicitly selects it.
    return stored === 'SIMULATION' ? 'SIMULATION' : 'MT5_DEMO'
  })

  useEffect(() => {
    if (source === 'MT5_DEMO') {
      mt5Api.status().catch(() => undefined)
    }
  }, [source])

  const setSource = useCallback((next: TradingSource) => {
    setSourceState(next)
    localStorage.setItem(STORAGE_KEY, next)
  }, [])

  const value = useMemo(() => ({ source, setSource }), [source, setSource])
  return <TradingSourceContext.Provider value={value}>{children}</TradingSourceContext.Provider>
}
