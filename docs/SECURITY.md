# Security Foundation

Phase 1 has no broker connectivity, real execution, credential form, execution adapter, or secret-bearing frontend configuration. The Laravel simulation endpoint validates a strict symbol allowlist, direction enum, and volume range. Its response explicitly confirms that no broker transmission occurred.

## Secret handling

- Never commit `.env`, private keys, tokens, passwords, terminal data paths, or broker credentials.
- Never expose secrets through Vite variables, browser storage, logs, analytics, or error messages.
- Future broker credentials require encrypted server-side storage, restricted decryption, key rotation, access audit, and redaction.
- Production exceptions return sanitized JSON; stack traces are disabled outside local development.

## Future controls

Authentication and authorization must precede any persistent account data. Execution requests require server-side policy checks, replay protection, idempotency keys, immutable audit records, account/environment allowlists, and authoritative risk approval. Live activation requires a separate privileged workflow and cannot be inferred from client state.

Dependencies must be reviewed and updated through the package managers. Database queries must remain portable and parameterized. Security and recovery exercises are roadmap work, not implied Phase 1 capabilities.
