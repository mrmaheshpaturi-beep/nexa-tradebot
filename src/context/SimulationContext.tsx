import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { phaseTwoApi } from '../api/services'
import { SimulationContext } from './simulationState'

export function SimulationProvider({ children }: { children: ReactNode }) {
  const [stopped, setStoppedState] = useState(true)
  useEffect(() => {
    phaseTwoApi.status().then((status) => setStoppedState(status.emergency_stop || !status.trading_enabled)).catch(() => setStoppedState(true))
  }, [])
  const setStopped = useCallback(async (enabled: boolean) => {
    await phaseTwoApi.emergencyStop(enabled)
    setStoppedState(enabled)
  }, [])
  const value = useMemo(() => ({ stopped, setStopped }), [stopped, setStopped])
  return <SimulationContext.Provider value={value}>{children}</SimulationContext.Provider>
}
