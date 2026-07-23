# Release

**Status:** Not releasable. Phase 0 has no application artifact.

Production deployment is an explicit operator action and never a side effect of
CI, a phase job, or an update check. Version 1 has no unattended auto-update.

## Release inputs

- committed reviewed source and `composer.lock`;
- supported runtime/dependency matrix;
- current migrations and upgrade/rollback notes;
- complete aggregate tests and mandatory safety properties;
- current-tree and reachable-history secret/private-data scans;
- immutable artifact built from an explicit runtime allowlist;
- exposure probes for Nginx and Apache;
- checksum and SBOM generated after all gates;
- completed documentation, threat review, accessibility and load acceptance;
- no critical/high unresolved finding or critical Doctor result.

## Artifact boundary

The artifact includes only runtime source/config/templates/public assets,
locked production dependencies, migrations, console/web entry points, and
required metadata. It excludes Git data, local environment files, prompts,
development docs/tools/dependencies, tests/fixtures/snapshots, cache, sessions,
logs, uploads, exports, backups, temporary/private data, and all key material.

An allowlist, not a growing denylist, controls contents. Artifact inspection
searches for credentials, private keys, personal data, prohibited extensions,
unsafe permissions, symlinks, debug/test packages, and public-path exposure.

## Candidate procedure

1. Build from a clean committed tree using the lockfile.
2. Run the complete quality pipeline and artifact probes.
3. Generate deterministic manifest, checksum, and SBOM.
4. Verify the artifact independently against its manifest.
5. Produce a release-candidate report with versions, migrations, known
   limitations, checks, rollback, and manual actions.
6. Stop. Tagging, publishing, deploying, DNS/provider changes, and production
   mail tests need separate explicit authorization.

Phase 9 supplies the final commands and acceptance report. Phase 0 deliberately
does not build, sign, tag, publish, or deploy anything.
