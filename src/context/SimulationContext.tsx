import { useMemo, useState, type ReactNode } from 'react'
import { SimulationContext } from './simulationState'

export function SimulationProvider({ children }: { children: ReactNode }) {
  const [stopped, setStopped] = useState(false)
  const value = useMemo(() => ({ stopped, setStopped }), [stopped])
  return <SimulationContext.Provider value={value}>{children}</SimulationContext.Provider>
}
