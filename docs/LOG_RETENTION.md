# Log Retention

Structured application logs should rotate with standard Laravel/log drivers.

Recommended retention (ops guidance):
- Application logs: 14–30 days
- SystemErrorRecord rows: retain until incident closure + 30 days
- Metric samples: downsample after 7 days; retain aggregates 90 days
- Health snapshots: 30 days
- Alerts: retain resolved 90 days

Exact host logrotate config is environment-specific (LOCAL/STAGING/DEMO_VPS).
