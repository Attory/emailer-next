# Release

**Status:** Not yet releasable. Phase 8 package/update tooling is implemented and rehearsed locally; Phase 9 produces and accepts the first `0.1.0` release candidate.

Production deployment is an explicit operator action and never a side effect of
CI, a phase job, or an update check. No release has an unattended auto-update.

## Phase 1 artifact evidence

The local gate runs:

```bash
bash tools/artifact/build.sh
bash tools/security/scan-secrets.sh
bash tools/security/scan-private-data.sh
bash tools/artifact/inspect.sh
bash tools/artifact/probe-public.sh
```

`build.sh` stages only paths named in `tools/artifact/allowlist.txt`, installs
production dependencies exactly from the lockfile, removes dependency
documentation/tests, compiles AssetMapper assets, and verifies platform
requirements. Inspection rejects top-level drift, development packages,
environments, Git/CI/docs/tests/runtime/private paths, sensitive operational
extensions, public symlinks, and extra public PHP entry points. The loopback
probe verifies exact liveness and 404 responses for representative forbidden
paths.

The generated `build/` directory is ignored test output. It is scanned but is
not a release candidate and must not be published or deployed.

## Release train

| Version | Scope | Phase |
| --- | --- | --- |
| `0.1.0` | Client-installable MVP: campaigns, unsubscribe, critical readiness, backup/restore, package/update | 9 |
| `0.2.0` | View-in-browser and optional tracking | 10 |
| `0.3.0` | Optional RSVP | 11 |
| `0.4.0` | Full Doctor, retention, operational dashboard, backup automation | 12 |
| `1.0.0` | Complete master-specification Version 1 acceptance | 13 |

## Package contract

A candidate is a reproducible semantic-versioned archive built from a clean
committed tree. It contains runtime files only. A deterministic manifest binds
the application version, source commit, supported platform, schema version,
migration set, file digests, and minimum supported source release. The archive
ships with a checksum, SPDX SBOM, and detached Ed25519 signature.

Signing private material remains outside Git, build output, logs, prompts, CI
artifacts, and client installations. Installation pins the trusted public key.
Package verification is fail-closed before any file, migration, or release
pointer change.

The package never contains client configuration, a master/recovery/signing
private key, SMTP credentials, recipient or campaign data, writable runtime
state, logs, sessions, exports, imports, or backups. Those paths remain outside
immutable release directories.

## Release inputs

- committed reviewed source and `composer.lock`;
- supported runtime/dependency matrix;
- current migrations and upgrade/rollback notes;
- complete aggregate tests and mandatory safety properties;
- current-tree and reachable-history secret/private-data scans;
- immutable artifact built from the explicit runtime allowlist;
- representative Nginx/Apache exposure probes;
- deterministic manifest, checksum, SPDX SBOM, and detached signature generated
  after all gates;
- completed documentation, threat review, accessibility and load acceptance;
- no critical/high unresolved finding or critical Doctor result.

## Candidate procedure

1. Build from a clean committed tree using the lockfile.
2. Run the complete quality pipeline and deployment-profile probes.
3. Generate the deterministic manifest, checksum, SPDX SBOM, and detached
   signature.
4. Verify the artifact independently against its manifest, digest, signature,
   and pinned trust key.
5. Rehearse a clean install and, after `0.1.0`, update the latest supported
   release through the same operator CLI used in production.
6. Exercise incompatibility, tamper, interruption, failed-health-check, and
   rollback-or-restore behavior.
7. Produce a release-candidate report with versions, migrations, known
   limitations, checks, rollback, and manual actions.
8. Stop. Tagging, publishing, deploying, DNS/provider changes, and production
   mail tests need separate explicit authorization.

Phase 8's builder can create a local deterministic archive and signed sidecars
for verification drills, but none is a published candidate. Phase 9 supplies
the first `0.1.0` archive and acceptance report. Every later
phase must supply a clean-install candidate and a supported-version update
candidate. Phase 1 deliberately does not build a distributable archive, sign,
tag, publish, release, or deploy anything.
