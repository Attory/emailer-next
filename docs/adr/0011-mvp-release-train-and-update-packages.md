# ADR 0011: MVP release train and operator-applied update packages

- **Status:** Accepted
- **Date:** 2026-08-22
- **Decision owners:** Emailer Next maintainers
- **Related phase:** Planning after Phase 5

## Context

The original roadmap deferred all release engineering until after every
version-1 feature. That prevents an earlier client-installable minimum product
and leaves the first deployed installation without a proven update path.
Reliable campaign delivery and unsubscribe are the minimum useful product, but
shipping them without backup, restore, compatibility checks, maintenance mode,
or rollback would create unacceptable operational risk.

## Decision

Introduce an MVP release train:

- `0.1.0` is the first client-installable MVP after durable campaigns,
  standards-compatible unsubscribe, critical sending gates, backup/restore,
  and release/update acceptance pass;
- optional view-in-browser/tracking, RSVP, and expanded operational automation
  are delivered in later `0.x` feature packages;
- `1.0.0` retains the complete version-1 definition in the master
  specification.

Every distributable release is an immutable, reproducible archive named for an
exact semantic version. It has an allowlisted runtime tree plus a deterministic
manifest containing the application version, source commit, supported
PHP/MariaDB range, schema compatibility, required migrations, file digests, and
minimum supported source version and an explicit supported-source allowlist. A
checksum, SPDX SBOM, and detached Ed25519
signature accompany the archive. Release signing private material is external
to Git and the application; installations pin the approved public verification
key through operator-controlled configuration.

Updates are explicit CLI/operator actions. No browser route accepts an update
archive, no HTTP route runs migrations or switches releases, and there is no
unattended auto-update. The update flow must:

1. verify signature, digest, manifest, version direction, platform, schema,
   paths, ownership, and free space;
2. acquire an installation-wide update lock, enter maintenance mode, stop new
   queueing, and gracefully quiesce workers;
3. create and verify an encrypted pre-update backup;
4. install into a new immutable release directory without modifying the active
   release;
5. run reviewed forward migrations, warm safe cache, and atomically switch the
   current release pointer;
6. run coarse and authenticated health/Doctor checks before resuming services;
7. record a redacted append-only update result.

Writable state, client configuration, private data, uploaded assets, backups,
and the master key remain outside versioned release directories. A package
contains none of them.

Database migrations remain forward-only. Code rollback is allowed only when
the release manifest proves the older code is compatible with the current
schema. Otherwise recovery uses the tested pre-update backup or a reviewed
forward fix; an operator never blindly points old code at a newer schema.

After `0.1.0`, each feature/operations phase must produce and test both a clean
artifact and an update artifact from the latest supported release. It must
exercise success, tamper, incompatibility, interruption, health-check failure,
and documented rollback/restore paths before that update can be published.

## Consequences

- Release and upgrade architecture is implemented before the first client
  deployment instead of being deferred to final version-1 hardening.
- Optional product features no longer block an installable MVP.
- Each later phase carries migration compatibility and package-upgrade evidence,
  increasing its acceptance work but reducing client deployment risk.
- A release archive may be generated in CI, but signing, publishing, installing,
  provider contact, and production deployment remain separately authorized
  operator actions.
- Pre-`1.0.0` semantic versions may add features, but compatibility breaks still
  require explicit manifest policy and migration/restore evidence.

## Security and privacy impact

Detached verification prevents an untrusted archive from being treated as an
update. Immutable releases and an atomic pointer keep the web process from
modifying source. Pre-update backup and fail-closed health checks reduce partial
upgrade damage. Excluding writable/private state prevents packages from
becoming a credential, recipient-data, log, or backup transport.

## Alternatives considered

- Waiting for all version-1 features before any release was rejected because it
  delays client feedback and does not force early update-path validation.
- Shipping immediately after campaign delivery was rejected because it omits
  unsubscribe, recovery, critical configuration gates, and upgrade safety.
- Browser-uploaded or unattended updates were rejected because they expand the
  remote attack surface and conflict with the operator-action requirement.
- In-place source replacement was rejected because partial writes and rollback
  are difficult to verify.

## Validation

Phase 8 implements the package/update/backup primitives and a non-public
baseline upgrade fixture. Phase 9 proves a clean `0.1.0` installation plus an
upgrade rehearsal between signed release candidates. Every post-MVP phase
repeats the supported-version upgrade matrix. Phase 13 closes the complete
version-1 acceptance checklist.
