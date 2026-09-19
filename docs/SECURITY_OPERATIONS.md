# Security Operations

- Secrets redacted in StructuredLogger / SecretRedactor
- `.env` gitignored; EnvValidator rejects LIVE flags
- Rate limits on auth + health probes
- RBAC: `observability.view|manage|operate`
- Auth/authz audited via existing AuditLog patterns
- Dependency locks via composer.lock / package-lock.json
- No paid notification provider required
