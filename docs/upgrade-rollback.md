# Upgrade and rollback

**Status:** Phase 1 migration baseline with an accepted MVP update contract;
production tooling begins in Phase 8 and the first candidate is rehearsed in
Phase 9.

There is no unattended update. Build, approval, deployment, migration, health
verification, and rollback are explicit operator actions using an immutable,
verified artifact.

Updates are CLI-only. There is no browser package upload, HTTP-triggered
migration or release switch, or web-accessible restore path.

## Current migration state

Phase 1 contains one reversible no-schema baseline migration:
`DoctrineMigrations\Version20260723000000`. Its `up()` and `down()` each execute
`SELECT 1`; applying it only records the version in Doctrine's metadata table.
It exists to smoke-test MariaDB and migration wiring, not to authorize a
production upgrade.

The Phase 1 gate runs migrations with `--all-or-nothing`, confirms there are no
pending migrations, and validates mapping metadata. There is no data conversion,
worker compatibility issue, backup requirement, or manual rollback action in
this phase.

## Preconditions

- Release source, lockfile, CI checks, secret/PII/history scans, artifact
  allowlist, checksum, and SBOM pass.
- Runtime/framework/database versions remain supported.
- Upgrade notes identify schema, config, worker, retention, and compatibility
  changes.
- A recent verified backup and usable recovery-key package exist.
- Rollback compatibility and migration behavior are documented and rehearsed.
- Worker/scheduler can be quiesced without corrupting delivery state.
- The package's deterministic manifest names its source commit, semantic
  version, platform/schema compatibility, migrations, file digests, and minimum
  supported source release.
- Its checksum, SPDX SBOM, and detached Ed25519 signature validate against the
  public key pinned by the operator.

## Planned sequence

1. Announce/enter maintenance mode and stop new campaign queueing.
2. Gracefully drain or stop workers according to the release policy.
3. Verify manifest, checksum, signature, version compatibility, ownership,
   paths, and free space before extracting into the releases area.
4. Create/verify a pre-upgrade backup.
5. Install into a new immutable release directory.
6. Run reviewed maintenance-mode migrations.
7. Switch the release pointer, clear/warm safe cache, and start services.
8. Run coarse and authenticated health/Doctor checks.
9. Observe queue/worker/scheduler/error state before ending maintenance.

Client configuration, the external master key, private data, writable runtime
state, and backups remain outside every immutable release directory. The
updater records only redacted version, migration, backup, health, and outcome
metadata.

Exact commands are not documented until they exist and pass a representative
rehearsal.

## Rollback

Rollback returns to a known artifact only when its code/schema compatibility is
proven. If migrations are not backward compatible, use the tested
backup/forward-fix plan instead of blindly pointing old code at a new schema.
Preserve audit, suppression, and ambiguous delivery evidence. Never resend
unknown ledger work to “catch up.”

An upgrade/rollback report records versions, migration IDs, checksums, backup
record, service health, operator, times, and redacted outcomes—never credentials
or private data.

## Supported update evidence

Phase 9 proves the `0.1.0` clean install and update mechanism. Each later phase
must produce both a clean-install candidate and an update candidate from the
latest supported release:

| Target | Required source-path rehearsal |
| --- | --- |
| `0.2.0` | latest supported `0.1.x` |
| `0.3.0` | latest supported `0.2.x` |
| `0.4.0` | latest supported `0.3.x` |
| `1.0.0` | every release line still declared supported |

Every rehearsal covers tampered and incompatible packages, interrupted update,
failed post-switch health, and the documented compatible rollback or verified
restore path. Exact support windows and signing-key custody must be decided
before the Phase 9 candidate is approved.
