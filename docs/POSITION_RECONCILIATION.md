# Position Reconciliation (Management)

UNKNOWN / timeout → reconcile first, never blind retry. Sync detects external close and manual MT5 SL/TP changes (ADOPT / ALERT / REQUIRES_REVIEW). Snapshots persisted. Crash/restart recovery via ManagementCrashRecoveryService.
