import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { marketApi } from '../api/services'
import type { MarketSnapshot } from '../api/types'
import { createPollingMarketDataTransport } from '../market/marketDataTransport'
import { useTradingSource } from './tradingSourceState'

export type MarketPrefer = 'bridge' | 'simulation'

interface MarketStoreValue {
  snapshot: MarketSnapshot | null
  loading: boolean
  error: string | null
  prefer: MarketPrefer
  lastUpdate: string | null
  reload: () => void
}

const MarketStoreContext = createContext<MarketStoreValue | null>(null)

export function MarketDataStoreProvider({ children }: { children: ReactNode }) {
  const { source } = useTradingSource()
  const prefer: MarketPrefer = source === 'MT5_DEMO' ? 'bridge' : 'simulation'
  const [snapshot, setSnapshot] = useState<MarketSnapshot | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [lastUpdate, setLastUpdate] = useState<string | null>(null)
  const [reloadNonce, setReloadNonce] = useState(0)

  useEffect(() => {
    const transport = createPollingMarketDataTransport(8000)
    let active = true
    const load = async () => {
      try {
        const data = await marketApi.snapshot({ prefer, candle_count: 40, persist: true })
        if (!active) return
        setSnapshot(data)
        setError(null)
        setLastUpdate(new Date().toISOString())
        setLoading(false)
      } catch (err) {
        if (!active) return
        const message = err instanceof Error ? err.message : 'Market data unavailable'
        setError(prefer === 'bridge' ? 'MT5 DATA UNAVAILABLE' : message)
        if (prefer === 'bridge') setSnapshot(null)
        setLoading(false)
      }
    }
    const stop = transport.start(load)
    return () => {
      active = false
      stop()
    }
  }, [prefer, reloadNonce])

  const value: MarketStoreValue = {
    snapshot,
    loading,
    error,
    prefer,
    lastUpdate,
    reload: () => {
      setLoading(true)
      setReloadNonce((value) => value + 1)
    },
  }

  return <MarketStoreContext.Provider value={value}>{children}</MarketStoreContext.Provider>
}

export function useMarketStore() {
  const ctx = useContext(MarketStoreContext)
  if (!ctx) throw new Error('useMarketStore requires MarketDataStoreProvider')
  return ctx
}
