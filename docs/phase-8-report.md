# Phase 8 completion report

## Identity and boundary

- Phase: 8 — MVP operations, recovery, and release/update tooling
- Branch: `codex/phase-8-mvp-operations-release`
- Verified base: `705165b432ad2df5f1bc729092cfaefd46adebdf`
- Repository: `Attory/emailer-next`
- Later scope: no Phase 9 release candidate, tag, publication, deployment,
  production SMTP/DNS/provider action, unattended update, or external backup
  destination was implemented.

## Implemented outcome

- Redacted on-demand/CLI Doctor with persistent stable findings for application
  and schema compatibility, external key, private paths/disk, canonical HTTPS,
  SMTP TLS/auth/sender, suppression, Owner MFA, delivery runtime/circuit,
  worker/scheduler heartbeat, backup recency/storage, and recovery package.
- Critical findings fail closed at manual queue, manual schedule, and scheduler
  materialisation. Acknowledgement is append-only audit evidence and cannot
  change a sending gate.
- Streaming versioned module-contributor backups cover application tables and
  approved template assets. Sodium secretstream encryption authenticates every
  chunk and final boundary; a manifest binds every entry and version.
- A process-reentrant installation-wide mutex excludes backup, restore,
  housekeeping, update, and readiness-checked resume races while allowing the
  updater's nested safety-backup/restore calls. Scheduler recovery still runs
  under unrelated critical findings but not after maintenance quiescence.
- CLI verification/restore enforces private single-file paths, maintenance and
  zero active claims, full preflight, verified pre-restore backup, compensating
  rollback, suppression preservation, asset restore, and post-restore
  quiescence.
- Separate recovery-key export uses hidden interactive passphrases, Argon2id13
  (3 operations, 64 MiB), and XChaCha20-Poly1305. Recovery can create only a
  missing master key and never overwrites one.
- Deterministic offline package tooling creates a semantic-version archive,
  manifest, SHA-256 sidecar, SPDX 2.3 SBOM, and detached Ed25519 signature.
  CLI inspection rejects bad signature/checksum/digest, unsafe paths/symlinks,
  incompatible source/PHP, and malformed metadata before extraction.
- CLI install/update uses immutable version directories, disk reserve, a
  non-overlap lock, delivery quiescence/drain, verified backup, forward
  migration, atomic `current` pointer, maintenance-aware Doctor, post-update
  quiescence for supervised process restart and explicit readiness-checked
  resume, and only an explicitly reviewed backward-compatible pointer rollback.
  Packages declaring an incompatible transition are inspection/clean-install-only.
- Bounded idempotent housekeeping covers expired import files, old revoked
  sessions, delivered queue rows, 90-day dead-letter rows, and old Doctor
  evidence in batches of 200. Log rotation is host-owned because application
  logs are stderr-only under the supplied supervised-service profile.
  It never deletes suppression, audit, campaign ledger, backup, or ambiguous
  delivery evidence.
- Authenticated views expose only redacted Doctor, backup metadata, retention,
  and release status. Restore, key recovery, package input/install/update,
  migration, pointer switching, rollback, and deployment remain CLI-only.

## Schema and dependencies

- Added forward-only `DoctrineMigrations\Version20260824000000` with
  `health_check_run`, `backup_record`, `release_operation`, and
  `housekeeping_run`, plus `Version20260824010000` for explicit dead-letter
  retention evidence.
- No new Composer dependency. Existing PHP sodium, ZIP, DBAL, Console, Lock,
  and process primitives are used.
- Added placeholder-only external path configuration for recovery package,
  pinned release public key, and immutable releases root.

## Executed evidence

- Pre-change current/reachable-history secret scan: passed.
- Pre-change private-data scan: passed.
- Migration/up-to-date/mapping gate: passed on MariaDB.
- Symfony container and command/route discovery: passed.
- PHP-CS-Fixer: passed.
- PHPStan maximum level, Deptrac, custom module boundaries, and package-builder
  syntax: passed.
- PHPUnit: 163 tests, 2,075 assertions, passed (all Phase 1–7 regressions plus
  Phase 8 Doctor, backup/restore, recovery, package, housekeeping, route/role,
  method, and CSRF coverage).
- Local signed-package double build was byte-identical for archive, manifest,
  checksum, SBOM, and signature. Independent CLI inspection passed; the
  ephemeral synthetic Ed25519 key and packages were then deleted.
- Encrypted restore drill: corruption rejected, suppression restored, and
  delivery left quiesced.
- A disposable empty MariaDB database applied all ten migrations through
  `Version20260824010000` in 76 SQL queries, exposed all four Phase 8 evidence
  tables, and was deleted. Representative clean immutable install, supported
  update, insufficient-space rejection, interrupted-migration restore, and
  failed-health pointer rollback drills passed.

The GitHub CI matrix, exact-head independent Claude audit, and Codex
verification are recorded after the review head is published.

## Manual actions and residual decisions

- Operators must provision the three external Phase 8 paths, retain recovery
  custody separately, supervise worker/scheduler processes, set RPO/RTO, and
  perform production web-server/provider verification in Phase 9.
- Signing-key custody/distribution, trusted-public-key rotation, supported
  release window, actual `0.1.0` candidate, and production deployment profiles
  remain Phase 9 decisions/evidence.
- External backup destinations and unattended scheduling remain Phase 12.
