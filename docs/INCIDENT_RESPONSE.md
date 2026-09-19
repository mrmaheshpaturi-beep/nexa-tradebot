# Incident Response

1. Pause new entries / SAFE_MODE via watchdog or kill switch
2. Do **not** blind-retry UNKNOWN executions — RECONCILE
3. Capture correlation IDs from SystemErrorRecord / logs
4. Acknowledge alerts; open OpsIncident
5. If restore required: BackupService verify → restore test → **reconcile before new trading**
6. Resume only with explicit operator action
7. Never enable LIVE during incident recovery
