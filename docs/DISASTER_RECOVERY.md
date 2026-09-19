# Disaster Recovery

1. STOP / PAUSE AUTO DEMO entry (scoped SAFE_MODE as needed)
2. Restore from verified backup (prefer isolated restore harness first)
3. Verify integrity + restore test
4. **Reconcile** broker vs app before any new trading (UNKNOWN → reconcile, never blind retry)
5. Confirm DEMO account verification
6. Explicit operator resume only
7. LIVE_PRODUCTION does not exist in this phase

Phase 19 APIs: `/api/v1/hardening/dr`, `/api/v1/hardening/backup`, `/api/v1/hardening/isolated-restore`

Full VPS restore drill remains **PENDING MANUAL** until executed in a real environment.
