# ADR 0005: Authenticated encryption with an external master key

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

SMTP passwords, provider API keys, webhook signing secrets, and TOTP seeds must
be recoverable by authorized application processes. Hashing is therefore
insufficient, while plaintext database/configuration storage would expose them
in a database theft or ordinary backup.

## Decision

Generate a 32-byte master key with `random_bytes()` and store it in an external
file with mode `0600` and parent mode `0700`, never in the database or ordinary
backup. Derive purpose-specific keys with HKDF and explicit context labels.

Encrypt each dynamic secret with libsodium XChaCha20-Poly1305, a fresh nonce,
and a versioned envelope. Associated data binds installation UUID, record type,
record ID, and schema version. Authentication failures fail closed. Master-key
rotation re-encrypts transactionally and rolls back on any failure.

Application passwords and recovery codes are one-way hashed instead.

## Consequences

- Losing both the master key and its verified recovery package makes encrypted
  records unrecoverable by design.
- The application needs restrictive filesystem access in addition to database
  access.
- Key/envelope versions and recovery procedures are durable compatibility
  concerns.
- Secrets are decrypted only at the adapter boundary and never redisplayed.

## Security and privacy impact

Database/ordinary-backup theft alone does not reveal reversible secrets.
Binding AAD prevents valid ciphertext being moved between records or
installations. Root/process-memory compromise remains outside the guarantee.

## Alternatives considered

- Plaintext environment or database values were rejected.
- Database-managed encryption with the key in the same database was rejected
  because it does not separate compromise domains.
- AES modes with manually composed authentication were rejected in favor of the
  specified misuse-resistant high-level libsodium primitive.
- A remote KMS was deferred because one-server self-hosting must work without an
  external control plane.

## Validation

Phase 3 tests round-trip, fresh nonce, tamper, wrong key/AAD/version, missing or
unsafe key, transactional rotation, and absence of plaintext in database,
queue, cache, session, log, exception, and backup outputs.
