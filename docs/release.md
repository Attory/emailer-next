# Release

## Public distribution preparation — 11 November 2026

The new `v1.3.12-public.1` distribution packages the current 1.3.12 runtime
from the exact licensed merge commit. It is separate from historical `v1.3.12`
assets; do not replace or alter those assets. First-party code and cleared
materials in the new revision are AGPL-3.0-or-later. Historical versions and
client agreements retain their original terms. Anton confirmed Soular controls
the necessary rights on 11 November 2026; third-party notices remain binding.

Use `tools/release/build-public-1.3.12.sh` with the existing Ripe signing
identity, independently pinned public key and empty external output directory.
The builder compares two package/setup passes, includes LICENSE and dependency
notices, and embeds the exact Git source ZIP and signed source offer. The runtime
footer points to its build commit. No dependency update or schema migration.

This is a fresh-install distribution, with matching-operation resume only.
It deliberately omits historical application packages and refuses use against
an earlier installation. It does not expand conversion or native update paths,
redeploy installations, rebuild Light or regenerate guide PDFs. See
[PUBLIC-INSTALL.md](../PUBLIC-INSTALL.md) for the exact download and instructions.

Before visibility, review all remote history/branches/tags/PR refs, issue and PR
text/attachments, available Actions logs/artifacts, and every historical release
attachment. Stop for any unresolved exposure; never rewrite history to clear it.
Existing proprietary assets may become readable, but this is not a grant to
redistribute them under AGPL. Preserve all original third-party conditions.

Under the explicit minimum-verification budget for this rollout, check licenses,
source/signatures/inventories, secret/private-data exposure and changed-file
syntax/format. Reuse unchanged acceptance; do not run aggregate CI, installer
matrices, staging or full-package download-backs. Record skipped checks as skipped.
Publish only the cleared new signed download, then verify anonymous repository,
LICENSE, exact source and release access before deploying Soular's page.

The older release procedures below are historical operator evidence.

## Current 1.1.0 installation release

Build final packages only from a clean reviewed merge commit. The installer and
application use the existing Ripe signing identity; do not generate replacement
keys. Keep the original public pin non-writable by group/others in the build
worktree. First finish and publish the compatible public Light 1.1.0 release.

Render the four reviewed screenshot-backed guides outside this repository using
the pinned `tools/docs/requirements.txt` toolchain and
`tools/docs/build-guides.py OUTPUT_DIRECTORY`. Inspect every rendered PDF page
and the HTML, links, selectable commands and screenshots. The source-bound
manifest is checked by `tools/docs/verify-built-guides.php`.

`tools/release/build-1.1.0-candidate.sh` takes the existing private key path,
original public pin, new output directory and authenticated predecessor directory.
Set `EMAILER_RELEASE_DOCUMENTATION_DIR` to the rendered private guide directory
and `EMAILER_LIGHT_RELEASE_ASSETS_DIR` to the unchanged final public Light assets.
Set `EMAILER_LIGHT_DOCUMENTATION_ASSETS_DIR` to the separately signed
`docs-v1.1.0-r1` Light guide assets. This documentation-only correction leaves the
original application runtime and source unchanged. Its original-key signed identity
binds the corrected guide source to that exact runtime source and SHA256.
The predecessor directory must contain that exact final Light runtime and
verification sidecars, the original Light 1.0.0 package and original Ripe 1.0.6.

The builder compares two runtime/setup builds, verifies release identity and
signatures, includes source/SBOM/notices and individual HTML/PDF guides, and
creates both Ripe-only and complete private seven-guide documentation ZIPs.
The complete ZIP authenticates Light's signed checksum inventory and binds its
runtime hash/manifest source to its document identity. It must never be uploaded
to the public Light repository. All assets are covered by the original Ripe
identity's signed SHA256SUMS. Test those exact final installer/runtime artifacts
before publishing the immutable version tag; do not modify published assets.

The older release procedures below are historical operator evidence.


The Ripe `1.0.6` release supports the exact Light `1.0.0` edition transition
through its installer and the native Ripe `1.0.5` update. Target schema is
`20261015000000`. Release availability requires green exact-candidate checks,
independent review, trusted signing from the merged commit and successful
rehearsals of that exact final package. Publication remains private and is
separate from any production deployment. Operator commands and recovery custody
are in [CLIENT-LIGHT-UPGRADE-1.0.6.md](CLIENT-LIGHT-UPGRADE-1.0.6.md) and
[CLIENT-UPDATE-1.0.6.md](CLIENT-UPDATE-1.0.6.md).

## Historical build records

The explicitly requested `0.9.x` packages are non-public human-acceptance
builds of the complete Phase 13 application. `0.9.1` supersedes the first
`0.9.0` walkthrough build with the resulting installer and interface repairs.
They exist to exercise the real root installation, browser workflow, and
accessibility checklist before `1.0.0` is finalized; they are not an additional
supported production update line.

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
migration set, file digests, minimum source release, and explicit supported
source-version allowlist. The archive
ships with a checksum, SPDX SBOM, and detached Ed25519 signature.

Signing private material remains outside Git, build output, logs, prompts, CI
artifacts, and client installations. Installation pins the trusted public key.
Package verification is fail-closed before any file, migration, or release
pointer change. The target version may inspect its own package for a clean
install rehearsal; `ReleaseManager::update` still rejects every same-version
update. This self-inspection exception does not add a source version to the
signed update allowlist.

Key custody, fingerprint distribution, rotation, recovery, and the supported
update window are fixed in [release-signing.md](release-signing.md).

`tools/release/build-1.0.0-candidate.sh` performs two deterministic builds for
schema `20260828000000`, declares the supported floor as source `0.1.0` and
schema `20260824010000`, explicitly accepts only `0.1.0`, `0.2.0`, `0.3.0`,
and `0.4.0`, and exercises target-package inspection under each declared source
identity. The integration gate separately builds, signs with a disposable key,
installs, and consumes manifest-bound runtime artifacts from each exact accepted
predecessor source tree. It bundles
the standalone verifier plus [CLIENT-INSTALL-1.0.0.md](CLIENT-INSTALL-1.0.0.md)
and [CLIENT-UPDATE-1.0.0.md](CLIENT-UPDATE-1.0.0.md). The operator key and
candidate outputs remain outside the repository.

For the combined `1.0.0` train, production update compatibility is enforced by
the authenticated target-version runtime, not by predecessor binaries whose
contracts predate the exact allowlist. Before root configuration, the operator
checks the standalone verifier/inspector hashes against the independently
approved candidate record and verifies the signed archive with the pinned key.
The frozen target tree then runs in the root-controlled one-shot updater with a
root-only source-version record; it refuses to proceed unless that value equals
the actual current release-pointer basename. It also verifies the retained
predecessor package and sidecars under the pinned key and requires the installed
tree to match that signed manifest. Publication is recursively root-owned and
non-writable.

The Phase 12 `build-0.4.0-candidate.sh` remains available for reproducibility;
it is not the Version-1 builder.

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
5. Rehearse a clean install and every declared source path using retained signed
   predecessor packages. CI builds equivalent manifest-bound fixtures from the
   exact accepted integrated trees with a disposable key; candidate acceptance
   must not mislabel those fixtures as historical operator-signed outputs.
6. Exercise incompatibility, tamper, interruption, failed-health-check, and
   rollback-or-restore behavior.
7. Produce a release-candidate report with versions, migrations, known
   limitations, checks, rollback, and manual actions.
8. Stop. Tagging, publishing, deploying, DNS/provider changes, and production
   mail tests need separate explicit authorization.

`tools/release/build-0.2.0-candidate.sh` builds twice from a clean committed
tree, verifies byte identity and the pinned signature, then emits the archive,
manifest, checksum, SPDX SBOM, signature, public-key transfer copy, and a local
candidate record. Its minimum source is `0.1.0`, its target schema is
`Version20260826000000`, and rollback after migration requires restoration of
the verified pre-update backup. These ignored local outputs are not a
publication. The Phase 9 builder remains available to reproduce `0.1.0`.

`tools/release/build-0.3.0-candidate.sh` applies the same deterministic signed
workflow for schema `20260827000000`, declares minimum source `0.2.0`, and
includes `CLIENT-UPDATE-0.3.0.md` beside the downloadable candidate files.

## Ripe 1.0.6 edition compatibility

The bounded Light 1.0.0 transition and native Ripe 1.0.5 update are documented
in [CLIENT-LIGHT-UPGRADE-1.0.6.md](CLIENT-LIGHT-UPGRADE-1.0.6.md) and
[RELEASE-1.0.6-IMPLEMENTATION.md](RELEASE-1.0.6-IMPLEMENTATION.md). The existing
`emailer:setup:install` CLI supports read-only inspection, execution, unfinished
recovery, acceptance and isolated old-Light-backup export. No HTTP update or
restore route is introduced. Contacts/lists remain independent of campaign-owned
Light recipients. Delivery remains held until explicit accepted readiness/resume.

The 1.3.4 candidate adds a one-time, exact-original 1.0.5 native repair route,
current native 1.3.3 maintenance, and the corresponding Light 1.2.8 schema profile.
Do not replace 1.0.6 or 1.3.3 assets. Build only new candidate outputs from a clean
reviewed commit with the established signer. Original-source package acceptance,
complete failure/interruption recovery and live held-service verification are
release gates; synthetic-key fixtures do not substitute for final candidate
acceptance. Production deployment remains a separate Administration action.
