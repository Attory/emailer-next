# First-run setup

**Status:** Design baseline; implementation is split across Phases 2 and 3.

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

Reset is CLI-only through the planned `emailer:setup:reset` command. It requires
interactive confirmation and a recent backup, refuses when production data
exists unless the explicit destructive flag is supplied, and never prints or
exports existing secrets. Exact command behavior and tests are Phase 2 work.
