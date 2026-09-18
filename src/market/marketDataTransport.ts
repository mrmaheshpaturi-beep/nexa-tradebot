export type MarketDataTransport = {
  start: (load: () => Promise<void>) => () => void
}

export function createPollingMarketDataTransport(intervalMs = 8000): MarketDataTransport {
  return {
    start(load) {
      let cancelled = false
      const tick = async () => {
        if (cancelled) return
        await load()
      }
      void tick()
      const id = window.setInterval(() => {
        void tick()
      }, intervalMs)
      return () => {
        cancelled = true
        window.clearInterval(id)
      }
    },
  }
}

/** Reserved for a future streaming implementation. */
export type FutureWebSocketMarketDataTransport = MarketDataTransport
