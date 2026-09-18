import { createContext, useContext } from 'react'

export type TradingSource = 'SIMULATION' | 'MT5_DEMO'

export interface TradingSourceValue {
  source: TradingSource
  setSource: (source: TradingSource) => void
}

export const TradingSourceContext = createContext<TradingSourceValue | null>(null)

const defaultValue: TradingSourceValue = {
  source: 'SIMULATION',
  setSource: () => undefined,
}

export function useTradingSource() {
  return useContext(TradingSourceContext) ?? defaultValue
}
