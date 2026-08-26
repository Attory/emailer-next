# Upgrade and rollback

**Status:** Phase 11 supports clean installation of `0.3.0` and update from supported `0.2.x`.

The `0.3.0` target schema is `Version20260827000000`. The package declares
`rollback_schema_compatible=false`: after migration, a failed update restores
the verified automatic pre-update backup before reinstating the prior immutable
pointer. Never point `0.2.x` code at the `0.3.0` schema.

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

## Implemented CLI sequence

1. Announce/enter maintenance mode and stop new campaign queueing.
2. Gracefully drain workers; interrupted claims are recovered immediately after
   the updater closes new claims, before it waits for a zero active count.
3. Verify manifest, checksum, signature, version compatibility, ownership,
   paths, and free space before extracting into the releases area.
4. Create/verify a pre-upgrade backup.
5. Install into a new immutable release directory.
6. Run reviewed maintenance-mode migrations.
7. Switch the release pointer and run the maintenance-aware Doctor while
   delivery remains quiesced.
8. Restart the supervised worker and scheduler units so no long-lived process
   retains old release code.
9. Run Doctor again, then use `emailer:delivery:resume`; it rechecks every
   critical finding before changing `accepting_work`.
10. Observe queue/worker/scheduler/error state before ending maintenance.

Client configuration, the external master key, private data, writable runtime
state, and backups remain outside every immutable release directory. The
updater records only redacted version, migration, backup, health, and outcome
metadata.

Use `emailer:release:inspect <package>` for non-mutating verification and
`emailer:release:install <package>` to populate a new immutable directory
without switching. Interactive `emailer:release:update <package>` runs the
verified backup/migration/switch/maintenance-Doctor flow and intentionally
leaves delivery quiesced. Restart `emailer-worker.service` and
`emailer-scheduler.service`, then run `emailer:delivery:resume` to perform the
final fail-closed readiness check. `emailer:release:check` emits only redacted
version, schema, and pointer-presence evidence. No HTTP route accepts a package
or triggers migration/switching.

`EMAILER_RELEASE_PUBLIC_KEY_FILE` pins the externally provisioned Ed25519 public
key and `EMAILER_RELEASES_DIR` holds semantic-version directories plus the
atomic `current` symlink. Only the offline `tools/release/build-package.php`
reads a signing private key; that key remains outside Git, artifacts, client
installations, logs, prompts, and CI output.

## Rollback

Rollback returns to a known artifact only when its code/schema compatibility is
proven. If migrations are not backward compatible, use the tested
backup/forward-fix plan instead of blindly pointing old code at a new schema.
For a package whose reviewed manifest declares
`rollback_schema_compatible=true`, the automated updater may restore the prior
release pointer after a failed post-migration health check. A `false`
declaration instead requires the updater to restore the verified pre-update
backup before reinstating the prior pointer; the supported source-to-target
path must have passed the recorded update and restore rehearsals. It must never
point old code at the incompatible migrated schema.
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
| `0.3.0` | latest supported `0.2.x` |
| `0.4.0` | latest supported `0.3.x` |
| `1.0.0` | every release line still declared supported |

Every rehearsal covers tampered and incompatible packages, interrupted update,
failed post-switch health, and the documented compatible rollback or verified
restore path. Exact support windows and signing-key custody follow
[release-signing.md](release-signing.md). The first production release has no
predecessor and is clean-installed; `0.1.0-rc.1` to `0.1.0` is an
acceptance-only signed updater rehearsal, not a promise that clients should
install prereleases.
