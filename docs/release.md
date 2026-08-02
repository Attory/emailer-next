# Release

**Status:** Not releasable. Phase 1 validates a production-shaped artifact but
does not create, sign, publish, or deploy a release.

Production deployment is an explicit operator action and never a side effect of
CI, a phase job, or an update check. Version 1 has no unattended auto-update.

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

## Future release inputs

- committed reviewed source and `composer.lock`;
- supported runtime/dependency matrix;
- current migrations and upgrade/rollback notes;
- complete aggregate tests and mandatory safety properties;
- current-tree and reachable-history secret/private-data scans;
- immutable artifact built from the explicit runtime allowlist;
- representative Nginx/Apache exposure probes;
- checksum and SBOM generated after all gates;
- completed documentation, threat review, accessibility and load acceptance;
- no critical/high unresolved finding or critical Doctor result.

## Candidate procedure

1. Build from a clean committed tree using the lockfile.
2. Run the complete quality pipeline and deployment-profile probes.
3. Generate deterministic manifest, checksum, and SBOM.
4. Verify the artifact independently against its manifest.
5. Produce a release-candidate report with versions, migrations, known
   limitations, checks, rollback, and manual actions.
6. Stop. Tagging, publishing, deploying, DNS/provider changes, and production
   mail tests need separate explicit authorization.

Phase 9 supplies the final commands and acceptance report. Phase 1 deliberately
does not build a distributable archive, sign, tag, publish, release, or deploy
anything.
