# ADR 0013: Authenticate predecessor state and durable security-file publication

- **Status:** Accepted
- **Date:** 2026-08-29
- **Decision owners:** Anton / Attory
- **Related phase:** Phase 13 — Version-1 hardening and acceptance

## Context

The `1.0.0` target supports direct updates from the four accepted predecessor
versions. Those binaries predate the combined exact source allowlist, so the
authenticated target runtime already owns the update decision. A root-only
recorded version and matching release-pointer basename prove naming consistency,
but do not prove that the installed bytes came from the accepted predecessor
package. The accepted predecessor manifests also predate the exact allowlist and
the Phase 13 license-decision rule, so treating them as new target packages would
incorrectly reject their historical signed contract.

Master-key replacement and rotation writes used file and directory durability
barriers, but initial key creation and recovery flushed userspace buffers without
requiring a file `fsync`. That asymmetry could acknowledge publication without
the same power-loss durability guarantee.

## Decision

Every update requires two independently named inputs: the target package and the
complete signed package for the exact installed predecessor. Before delivery is
quiesced, a backup is created, a migration runs, or a pointer changes, the target
runtime:

1. verifies the predecessor archive, manifest, checksum, SBOM, and detached
   signature against the operator-pinned public key;
2. accepts the legacy manifest shape only in installed-source mode and only when
   its version exactly equals the root-recorded source version;
3. recursively compares every installed predecessor file to the signed manifest,
   rejects missing, extra, linked, non-regular, multiply linked, or changed files;
4. separately verifies the target under the current stricter manifest, explicit
   source allowlist, and license-decision contract; and
5. retains root-controlled, recursively non-writable release publication.

All master-key and rotation-journal file writes use one fail-closed file
durability contract after `fflush` and before publication. Every
security-relevant namespace change retains its containing-directory durability
barrier. A runtime without working `fsync` support cannot create, recover,
replace, rotate, journal, or roll back key material.

## Consequences

Operators must retain or obtain the exact signed predecessor archive and all four
sidecars in addition to the target candidate. The supervised update unit passes
that package explicitly. Missing evidence, a different pinned key, signature or
manifest failure, installed-tree drift, or version mismatch blocks the update
before operational state changes.

The legacy exception cannot authorize an update target and cannot relax the
`1.0.0` target package. Signing-key rotation must retain the predecessor trust
key and evidence for the supported update window or use the documented bridge
and re-trust procedure.

## Security and privacy impact

The update no longer infers predecessor provenance from a directory name. It
cryptographically binds installed immutable code to retained signed evidence and
detects local drift before privileged mutation. No package contains client
configuration, secrets, recipient data, runtime state, or backups. Disposable
CI signing keys remain test-only and are destroyed with temporary fixtures.

File and directory durability failures now fail closed before a key operation is
reported successful, reducing crash and power-loss ambiguity without logging key
material.

## Alternatives considered

- Trust only the root-owned version record and pointer basename: rejected because
  names do not authenticate installed bytes.
- Delegate to predecessor updaters: rejected because their signed contracts do
  not express the combined `1.0.0` source allowlist.
- Retrofit provenance records into already accepted releases: rejected because
  that would alter immutable historical artifacts.
- Accept legacy manifests as ordinary targets: rejected because it would weaken
  the current target package and license policy.

## Validation

- Integration tests build runtime artifacts from each exact accepted integrated
  `0.1.0`–`0.4.0` source tree, package and sign them with a disposable key, install
  their real manifest-bound contents, and update through the authenticated
  target runtime.
- Negative tests cover signature tamper, installed-file drift, recorded-source
  mismatch, unsupported source, and approved-target mismatch before backup or
  delivery quiescence.
- Unit tests prove legacy packages are accepted only as exact installed-source
  evidence.
- File-durability tests cover creation and recovery barrier invocation and prove
  failed barriers publish neither a key nor staging residue.
- The complete quality, security, artifact, isolated-network, privilege, and CI
  gates remain release-blocking.
