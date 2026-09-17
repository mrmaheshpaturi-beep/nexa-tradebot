import { phaseTwoApi, makeOrderIdentity } from '../api/services'
import type { SimulationOrderInput } from '../api/types'

export const persistentServices = {
  dashboard: { getSummary: phaseTwoApi.dashboard },
  strategies: { getStrategies: phaseTwoApi.strategies },
  risk: { getProfiles: phaseTwoApi.riskProfiles, setEmergencyStop: phaseTwoApi.emergencyStop },
  accounts: { getAccounts: phaseTwoApi.brokerAccounts },
  notifications: { getNotifications: phaseTwoApi.notifications, markRead: phaseTwoApi.readNotification },
  audit: { getEvents: phaseTwoApi.auditLogs },
  settings: {
    getAll: phaseTwoApi.settings,
    update: phaseTwoApi.updateSetting,
    getPreference: phaseTwoApi.preference,
    updatePreference: phaseTwoApi.updatePreference,
  },
  orders: {
    createIdentity: makeOrderIdentity,
    submit: (input: Omit<SimulationOrderInput, 'command_id' | 'idempotency_key'>, identity = makeOrderIdentity()) =>
      phaseTwoApi.submitSimulationOrder({ ...identity, ...input }),
  },
}

// Deliberately retained fixture boundary: Phase 2 has no market-data APIs.
export { services as mockMarketServices } from './mockServices'
