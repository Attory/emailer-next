# First-run setup

**Status:** Phase 2 ownership proof and state machine implemented; Phase 3
credential wizard remains design only.

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
3. Installation keys: external random 32-byte master key, installation UUID,
   and purpose-specific derived keys.
4. Organisation: singleton display name, canonical base URL, time zone, locale.
5. Initial Owner: individual login, Argon2id password, optional TOTP, one-time
   recovery codes with saved acknowledgement.
6. Mail: transport metadata, sender identity, TLS verification, fake/approved
   test, then encrypted secret persistence without redisplay.
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
reset remains unavailable until Phase 8 supplies a supported artifact. The
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
  credential-free CLI ownership marker. It does not collect credentials or
  create the final installation lock.
- `emailer:setup:reset` has the reset lifecycle and all guards, but the
  production backup validator intentionally accepts no Phase 2 artifact.
- Phase 2 adds no database schema, master key, Owner, organisation, SMTP
  transport, recipient data, worker, or sending behavior. Those remain bounded
  to later phases.
