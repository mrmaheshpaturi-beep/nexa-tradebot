# Security Operations

- Secrets redacted in StructuredLogger / SecretRedactor
- Phase 19 secret inventory: values never returned; frontend/Git forbidden
- `.env` gitignored; EnvValidator + AppBrokerEnvironmentService reject LIVE flags
- Rate limits on auth + health probes
- Security headers via `ApplySecurityHeaders` middleware
- RBAC: `observability.view|manage|operate`, `hardening.view|manage|operate|deploy|secrets`
- MFA challenge foundation for sensitive ops (not full TOTP product)
- Service/node identity fingerprints; replay nonces
- Auth/authz audited via existing AuditLog patterns
- Dependency locks via composer.lock / package-lock.json
- Bundle/secret scan: `scripts/phase19-production-hardening-audit.sh`
- No paid notification provider required
- Soak/chaos never against LIVE
