# Phase 9 completion report

## Identity and boundary

- Phase: 9 — MVP `0.1.0` release acceptance
- Branch: `codex/phase-9-release-candidate-deployment`
- Verified base: `0d7b2dbf25a9861cea7147fb627bf04205b6fa4d`
- Repository: `Attory/emailer-next`
- Later scope: no Phase 10 view-in-browser/tracking route, analytics behavior,
  schema, worker or package was implemented.
- External action boundary: no tag, publication, deployment, DNS/provider
  change, production credential rotation, live SMTP contact or client-server
  mutation was performed.

## Implemented outcome

- Advanced the supported matrix to PHP 8.4.24 and 8.5.9 while retaining
  MariaDB 10.11.18/11.8.8, Composer 2.10.2, Mailpit 1.30.5 and Gitleaks 8.30.1.
  Locked Symfony 7.4 components were refreshed through 7.4.17 where released;
  the locked dependency audit reports no advisory.
- Set the application candidate version to `0.1.0` and added deterministic
  double-build tooling for an exact-source ZIP, manifest, SHA-256 sidecar, SPDX
  2.3 SBOM with declared package licenses, and detached Ed25519 signature.
- Hardened build and verification against malformed semantic versions,
  mismatched archive/SBOM identity, absent runtime files, linked or swapped key
  files, unsafe key permissions, unbounded metadata, unsafe ZIP objects,
  duplicate/missing entries, manifest/runtime-version drift and output
  replacement.
- Added the operator-controlled offline signing-key custody, independent public
  fingerprint, rotation, compromise/loss and supported-update policy. Test/CI
  keys are disposable and cannot become release trust anchors.
- Rehearsed a real generated archive through exact source/version/signature
  verification and immutable clean installation. The update suite now models
  the acceptance-only `0.1.0-rc.1` to `0.1.0` path, including failed-health
  pointer rollback, interrupted-migration verified restore, incompatible schema
  and inadequate disk.
- Added the complete campaign approval summary required for MVP acceptance:
  selected/suppressed/eligible audience, immutable template version, sender,
  schedule/time zone, tracking/RSVP/one-click state and test-send state.
- Added semantic table headers, file-input labeling, visible keyboard focus,
  responsive navigation/table behavior, and executable static Twig/CSS
  structural checks. No browser, assistive-technology, tab-order or contrast
  conformance claim is made.
- Added a reference 2,000-recipient synthetic audience profile. It proves
  snapshot/ledger materialisation and the configured 1,000-message initial
  dispatch batch within 45 seconds and 128 MiB incremental memory; it is not a
  throughput guarantee for an arbitrary client server.

## Schema, dependencies and files

- Migrations: none. The schema remains `Version20260824010000`.
- Dependencies: no new package; `composer.lock` refreshes the existing Symfony
  7.4 constraints and transitive packages after the Phase 9 upstream review.
- Runtime routes/commands: no new application route or command. Phase 9 adds
  offline build/verification wrappers and acceptance tests only.
- Development-only material remains excluded by the production artifact
  allowlist. Candidate output is ignored local evidence, never source content.

## Executed local evidence

- `composer validate --strict` and locked dependency/advisory audit: passed.
- PHP-CS-Fixer, PHPStan maximum level, Deptrac and custom module boundaries:
  passed with no baseline, ignore or skipped violation.
- Doctrine migrations/up-to-date and mapping validation: passed on MariaDB.
- PHPUnit: 172 tests and 2,264 assertions passed on PHP 8.4.24, including all
  Phase 1–8 regressions plus Phase 9 packaging, accessibility and load checks.
- Artifact build, exact allowlist inspection, public-path and representative
  Nginx/Apache probes: passed.
- Current/reachable-history Gitleaks and private-data scans: passed; no scanner
  suppression or allowlist was added.
- Candidate tooling: two archive/manifest/checksum/SBOM/signature builds were
  byte-identical; independent exact-source verification and real immutable
  clean install passed; disposable key and output were removed.
- Signed update/rollback/restore matrix: passed.
- `git diff --check`: passed. No `TODO` or `FIXME` exists in the implemented
  security-critical application, configuration, template or release-tool path.

## MVP acceptance checklist

- Complete Phase 1–8 regression and mandatory safety properties: passed.
- Dependency/runtime/database revalidation and three-entry CI matrix: locally
  configured; GitHub matrix result is recorded at the final review head.
- Full-history secret/private-data and production-artifact scans: passed.
- Deterministic semantic-versioned package, manifest, checksum, SPDX and
  detached-signature path: passed with a disposable non-release test key.
- Clean install and signed update/rollback/restore rehearsal: passed.
- Static structural accessibility baseline and approved-limit load evidence:
  passed. Browser/assistive-technology and manual contrast testing were not
  performed by this phase gate.
- Installation, operation, backup, restore, update, rollback, signing and
  support-window documentation: completed.
- Critical/high findings: none unresolved in local review; exact-head Codex and
  independent Claude review are recorded after the branch head is published.
- Operator release-key build: required at the final exact head before the
  candidate can be declared accepted; no private signing key is generated or
  taken into custody by the repository or CI.

## Manual actions and residual limits

- The release owner must provision the external Ed25519 private key and the
  independently verified pinned public key under
  [release-signing.md](release-signing.md), then run the final candidate builder
  against the exact reviewed commit. That is a credential/custody action, not a
  CI default.
- Client infrastructure, TLS/reverse proxy, external runtime secrets, worker
  supervision, backup destination/RPO/RTO, SMTP/provider/DNS readiness and
  deployment approval remain operator responsibilities.
- Full retention automation/external backup destinations remain Phase 12. MVP
  tracking, view-in-browser and RSVP are absent and remain later phase scope.
