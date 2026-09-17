import { createContext, useContext } from 'react'

export interface SimulationState {
  stopped: boolean
  setStopped: (stopped: boolean) => void
}

export const SimulationContext = createContext<SimulationState | undefined>(undefined)

export function useSimulation() {
  const context = useContext(SimulationContext)
  if (!context) throw new Error('useSimulation must be used within SimulationProvider')
  return context
}
