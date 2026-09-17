import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'

interface SimulationState {
  stopped: boolean
  setStopped: (stopped: boolean) => void
}

const SimulationContext = createContext<SimulationState | undefined>(undefined)

export function SimulationProvider({ children }: { children: ReactNode }) {
  const [stopped, setStopped] = useState(false)
  const value = useMemo(() => ({ stopped, setStopped }), [stopped])
  return <SimulationContext.Provider value={value}>{children}</SimulationContext.Provider>
}

export function useSimulation() {
  const context = useContext(SimulationContext)
  if (!context) throw new Error('useSimulation must be used within SimulationProvider')
  return context
}
