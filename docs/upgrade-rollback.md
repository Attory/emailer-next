# Upgrade and rollback

**Status:** Phase 0 design; tooling begins in Phase 8 and is rehearsed in Phase 9.

There is no unattended update. Build, approval, deployment, migration, health
verification, and rollback are explicit operator actions using an immutable,
verified artifact.

## Preconditions

- Release source, lockfile, CI checks, secret/PII/history scans, artifact
  allowlist, checksum, and SBOM pass.
- Runtime/framework/database versions remain supported.
- Upgrade notes identify schema, config, worker, retention, and compatibility
  changes.
- A recent verified backup and usable recovery-key package exist.
- Rollback compatibility and migration behavior are documented and rehearsed.
- Worker/scheduler can be quiesced without corrupting delivery state.

## Planned sequence

1. Announce/enter maintenance mode and stop new campaign queueing.
2. Gracefully drain or stop workers according to the release policy.
3. Verify artifact checksum, version, ownership, paths, and free space.
4. Create/verify a pre-upgrade backup.
5. Install into a new immutable release directory.
6. Run reviewed maintenance-mode migrations.
7. Switch the release pointer, clear/warm safe cache, and start services.
8. Run coarse and authenticated health/Doctor checks.
9. Observe queue/worker/scheduler/error state before ending maintenance.

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
