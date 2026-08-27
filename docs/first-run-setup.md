# First-run setup

**Status:** Phase 3 secure first-run wizard implemented.

## State model

An installation without `var/private/install.lock` is in setup mode. Normal
admin/campaign routes return setup-required, the worker refuses delivery, and
only minimal setup and coarse liveness are exposed. A completed installation
never reopens setup through a URL, cookie, or database edit; setup routes return
404.

## Filesystem ownership proof

The first request creates a 160-bit random code encoded as uppercase Crockford
Base32 and a separate random challenge identifier. Only the identifier, SHA-256
hash of normalized code, timestamps, failed-attempt count, and requesting-IP
hash are stored in a mode-`0600` private state file.

The page displays the fixed path:

```text
<APPLICATION_ROOT>/.emailer-install-proof
```

The operator creates that file with only the displayed code. Verification:

- reads only that fixed path and never accepts the code in URL/body;
- uses `lstat`, rejects links/non-regular files, sizes over 256 bytes, and
  group/world-write permission;
- trims only documented line-ending whitespace;
- uses constant-time comparison and one generic public failure;
- expires after 15 minutes and at 10 attempts, with rate limiting and rotation;
- logs challenge ID/result only;
- deletes proof and state on success and rotates setup session ID.

Only one global challenge may be active. Under the same filesystem lock used by
verification, an ordinary `GET /setup` refuses to replace unexpired state below
the attempt ceiling. Because the raw code is never retained, it is not
redisplayed; the original setup session must complete it or wait for expiry.
Expired or exhausted state may be replaced atomically.

A wrong or missing proof is retryable by the same setup session against the
same challenge and increments its stored count. The tenth failure deletes the
state and terminates that session authority. Expired, missing, exhausted, or
identifier-mismatched state is terminal. Public responses remain generic.

## HTTPS gate

The proof instruction may be shown over HTTP, but Owner passwords, database/mail
credentials, MFA/recovery material, and backup passphrases are accepted only
over verified HTTPS with explicit trusted proxies. If HTTPS is unavailable
after proof, web setup stops and gives exact server guidance. A CLI installer is
the non-HTTP alternative.

## Wizard sequence

1. System preflight: runtime, extensions, public/private paths, modes, TLS,
   database driver, writability, and worker/scheduler capability.
2. Database: test without printing DSN; put its password in server runtime
   configuration; run reviewed migrations.
3. Installation keys: explicitly configured absolute external path, random
   32-byte master key, installation UUID, and purpose-specific derived keys.
4. Organisation: singleton display name, canonical base URL, time zone, locale.
5. Initial Owner: individual login, Argon2id password, optional TOTP, one-time
   recovery codes with saved acknowledgement.
6. Mail: exact plaintext/unauthenticated local Mailpit metadata, sender
   identity, and fake test evidence. Production TLS/auth configuration remains
   unavailable.
7. Delivery limits: bounded rates, concurrency, retry and import/campaign caps.
8. Privacy defaults: tracking and RSVP off, retention/suppression/footer policy.
9. Worker/scheduler: preferred supervision or constrained cron, heartbeat.
10. Final Doctor: block critical conditions, write non-secret install lock,
    disable setup, append initial audit event.

The wizard is resumable without plaintext secrets in setup state. A failed
secret step must require re-entry rather than storing the secret for later.

## Reset and recovery

Reset is CLI-only through `emailer:setup:reset`. It requires interactive
confirmation and a recent integrity-authenticated backup, refuses when
production data exists unless the explicit destructive flag is supplied, and
never prints or exports existing secrets. Phase 2 has no trusted backup producer
or authentication key, so a self-declared checksum cannot establish meaningful
backup provenance. Production backup validation therefore fails closed and
Phase 8 now binds reset to the authenticated encrypted backup verifier. Reset
can proceed only with a verified artifact created within 24 hours, while the
data, `--destructive`, confirmation, CLI-only, and no-secret boundaries remain
enforced and independently tested.

For negative validation only, the Phase 2 parser recognizes a bounded JSON
envelope with format `emailer-next-backup-evidence-v1`, an integer
`created_at`, a non-empty base64 `payload`, and its lowercase SHA-256 digest.
Even a matching envelope is rejected: the digest detects corruption but is
self-declared and therefore does not authenticate backup provenance.

## Implemented Phase 2 boundary

- `GET /setup`, `POST /setup/proof/verify`, and `GET /setup/preflight` implement
  the proof and preflight sequence; setup pages are script-free and `no-store`.
- Challenge issuance is limited to 6 per 15 minutes per client and proof posts
  to 10 per 15 minutes, in addition to the challenge's ten-attempt ceiling.
- `emailer:setup:install` runs safe preflight and records only a
  credential-free CLI ownership marker. With `--complete`, protected
  interactive hidden prompts drive the same setup services through permanent
  finalization without placing secrets in arguments, output, or setup state.
  CLI setup deliberately skips MFA enrollment so it never prints TOTP/recovery
  material; the Owner must enroll MFA later through authenticated HTTPS.
- `emailer:setup:reset` has the reset lifecycle and all guards, but the
  Phase 2 self-declared evidence remains rejected. Phase 8 encrypted backup
  verification is now the only accepted producer.
- Phase 2 adds no database schema, master key, Owner, organisation, SMTP
  transport, recipient data, worker, or sending behavior. Those remain bounded
  to later phases.

## Implemented Phase 3 boundary

- `/setup/database` verifies submitted values against the externally supplied
  runtime connection, contacts only that MariaDB instance, applies reviewed
  migrations, and never stores or logs the password.
- Key initialization requires an explicit absolute production path outside the
  application/release/public tree. It walks and snapshots the complete existing
  ancestor chain, rejects link redirection and unsafe ownership/modes, creates a
  random 32-byte key in a mode-`0700` directory and mode-`0600` file, and fails
  closed on missing, malformed, hard-linked, moved, or replaced state.
- `/setup/organisation`, `/setup/owner`, `/setup/mail`, and `/setup/policy`
  persist completed durable state only. Passwords are Argon2id-hashed; TOTP
  seeds are encrypted; recovery codes are individually Argon2id-hashed and
  single-use. An Owner retry must verify the same password, and restarting or
  skipping MFA removes inactive seeds/codes so only one applicable TOTP set can
  exist. Plaintext submissions are not retained for retries or redisplay.
- Mail setup rejects every destination except local Mailpit port 1025 and also
  rejects STARTTLS, implicit TLS, usernames, and passwords. It sends a marked
  plaintext synthetic message and stores exactly that unauthenticated test
  configuration with no TLS-verification claim. Production SMTP remains
  unavailable.
- Every Phase 3 writer and finalization shares one lifecycle lock and verifies
  the database/filesystem completion boundary immediately before mutation.
  Finalization runs the critical preflight, records one completion event,
  publishes the restrictive lock, and compensates database completion if
  publication fails. No setup record changes after publication; routes return
  an empty 404 permanently.
- `emailer:secrets:replace` remains an argument-free fail-closed skeleton;
  production SMTP credentials are replaced through the Owner-authorized HTTPS
  configuration workflow. `emailer:secrets:rotate-master-key` is a protected
  interactive operation: it requires active Owner password/MFA
  re-authentication, quiesced delivery, an automatically created verified
  backup, installation-wide maintenance locking, row-locked transactional
  re-encryption, atomic restrictive key replacement, compensation on failure,
  and redacted audit evidence.

Phase 3 adds no login/logout, general user management, database-backed
sessions, admin shell, contacts, campaigns, queue messages, delivery worker,
production SMTP, full Doctor, backup engine, restore, or release behavior.
