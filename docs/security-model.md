# Security model

**Status:** Phase 0 design lock

Security controls are default-deny and layered. A UI-hidden action is not a
security boundary; every protected invariant is checked at the route,
application-service, persistence, and operational boundary appropriate to it.

## Fail-closed states

The application does not send when setup is incomplete/reopened, HTTPS is
absent, the public/private boundary is invalid, the master key is missing or
unsafe, migrations are pending, the suppression store is unavailable, mail
configuration cannot decrypt/authenticate, the selected worker/scheduler mode
is unhealthy, an artifact scan finds likely secret/private material, or
debug/test mode is active.

Setup mode exposes only the minimal setup flow and coarse liveness. A completed
installation has a private install lock; setup routes then return 404.

## Identity and authority

Every administrator has an individual account:

| Role | Authority ceiling |
| --- | --- |
| Owner | Secrets, recovery, users/roles, restore/upgrade approval, and all lower-role actions |
| Administrator | Configuration, users except protected Owner operations, content, contacts, campaigns, and reports |
| Operator | Contacts, templates, campaign workflow, test sends, and pause/resume/cancel; no security/secrets/backups/roles |
| Auditor | Read-only redacted reports, history, health, and audit |

Authorization is deny-by-default at both route and application-service level.
At least one active Owner always remains. Secret replacement, recovery,
privileged identity changes, restore/upgrade approval, and other sensitive
operations require recent re-authentication.

Passwords use framework-supported Argon2id settings. Login/recovery responses
are generic and throttled by account and source. TOTP is optional in version 1,
but production health cannot be green while an Owner lacks MFA unless an
operator-approved policy exception is recorded; the exception remains visible
as a warning. Recovery codes are random, hashed, displayed once, and consumed
once.

## Sessions and browser security

Sessions are server-side in MariaDB and have idle and absolute expiry. Session
IDs rotate on login, logout, privilege/password/MFA change, recovery,
re-authentication, and setup proof success as applicable. Users can view and
revoke their sessions.

Production cookies are `Secure`, `HttpOnly`, and `SameSite=Lax` or stricter
after compatibility tests, with narrow host/path scope. Trusted hosts/proxies
are explicit. Sensitive admin responses prohibit caching. A reviewed policy
sets HSTS after HTTPS validation, CSP without unsafe wildcards, `nosniff`,
frame-ancestor protection, Referrer-Policy, and Permissions-Policy.

Every browser mutation uses an unsafe HTTP method and CSRF. Public recipient
actions do not share the admin firewall/session and use their scoped token as
authority; they receive dedicated rate limits and cookie-free responses.

## Secret hierarchy

```text
external 32-byte master key
  -> HKDF context: encrypted-record/<record-type>/<key-version>
  -> XChaCha20-Poly1305 envelope
     AAD: installation UUID + record type + record ID + schema version
```

- The master key is generated with `random_bytes(32)`, stored outside `public/`
  with file mode `0600` and parent mode `0700`, and never stored in the database,
  repository, queue, log, session, browser, or ordinary backup.
- Each encrypted record uses a fresh nonce and a versioned envelope.
- Authentication failure is handled uniformly and fails closed.
- Passwords and recovery codes are one-way hashed, never encrypted.
- Rotation is transactional; any failure rolls back. Audit records contain
  lifecycle metadata but no secret value.
- The ordinary backup excludes the plaintext master key. A separate
  passphrase-encrypted recovery package is generated and verified explicitly.

The database stores no environment DSN password. Runtime database credentials
come from server configuration outside the repository and are never printed.

## Recipient tokens

Public tokens are opaque, purpose scoped, campaign/list and recipient scoped,
versioned, time bounded according to policy, and constant-time verified. They
contain no raw address. A token for tracking cannot unsubscribe; a token for one
campaign cannot authorize another. Key rotation supports a bounded overlap
window. Repeated unsubscribe is safe and idempotent.

The Phase 7 implementation may choose a random database-backed handle or an
authenticated encrypted payload only after comparing revocation, forwarding,
retention, and key-rotation consequences.

## Data and output controls

- Private data is minimized and classified in
  [data-model.md](data-model.md).
- Raw recipient data is absent from public URLs, logs, exception text, metrics
  labels, queue names/payloads, filenames, and correlation IDs.
- Operational views mask addresses. Full display/export is separately
  authorized and audited.
- Production logs are structured JSON with UTC time and random correlation IDs.
  Central processors strip credentials, tokens, cookies, headers, addresses,
  bodies, uploaded rows, control characters, and unsafe exception detail.
- Audit events are append-only and store opaque targets and redacted change
  summaries.
- Public health is coarse. Detailed Doctor evidence is authenticated/CLI-only
  and safe to export.

## File, process, and network boundaries

Only `public/` is served. Private uploads, exports, setup state, logs, backups,
cache, dependencies, and key files are outside it. Server-chosen random names,
content-based MIME checks, bounded sizes, restrictive modes, and symlink
rejection protect file operations.

Delivery and restore are CLI-only. The web process cannot start a worker.
Workers receive only the permissions needed for their queue, data, key, and mail
operations. Source and immutable release files are not web-writable.

Development and CI use fake SMTP and must be unable to resolve or connect to a
live SMTP target by default. No Doctor or setup operation modifies DNS,
third-party accounts, or credentials automatically.

## Supply-chain and release boundary

Composer dependencies are locked and audited. CI performs formatting, static
analysis, tests, migration checks, current-tree and full-history secret/private
data scans, and artifact inspection before checksum/SBOM generation. Release
artifacts are allowlisted and omit Git metadata, local environments,
development tools, tests, private/runtime data, logs, backups, and fixtures.

A likely credential, private key, or private-data finding blocks merge and
release. Suppression of a scanner finding requires review showing it is a safe
placeholder or false positive; a blanket path exclusion is prohibited.

## Security exceptions

An exception must name its owner, scope, expiry, threat-model impact,
compensating controls, test evidence, and removal plan. It cannot permit a real
secret/private record in Git, disable TLS peer verification, bypass suppression,
reopen setup, expose a private path, enable HTTP delivery, or claim exactly-once
SMTP semantics.
