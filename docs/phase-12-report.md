# Phase 12 completion report

## Identity and boundary

- Phase: 12 — Full Doctor, retention, operational dashboard, and backup automation (`0.4.0`)
- Branch: `codex/phase-12-full-operations`
- Verified base: `e1756ae25b391ac9c8d4fd1c2b60e7c25cc9e13a`
- Repository: `Attory/emailer-next`
- Later scope: Phase 13 Version 1 hardening and acceptance are absent.
- Implementation-branch boundary: no merge, tag, operator-key signature,
  publication, deployment, credential or DNS change, client-server mutation,
  provider contact, or live SMTP use was performed before exact-SHA approval.
  The authorized signature and merge are recorded below; publication,
  deployment, client mutation, and external-provider contact remain absent.

## Implemented outcome

- Classifies readiness probes as lightweight, full, and/or sending-gating.
  Successful login triggers a persisted 15-minute cached lightweight run;
  full runs remain daily and on demand. Probe failures and all exported state
  are bounded and redacted.
- Persists active finding state with summary, risk, evidence, remediation,
  first/last observation, acknowledgement, and explicit sending-block state.
  Critical findings produce a persistent admin banner; acknowledgement records
  awareness without disabling gates.
- Replaces the dashboard shell with contract-composed redacted Health,
  Delivery, Backup, Release, and Audit status. It projects counts, stable
  status codes, and timestamps only.
- Adds bounded Operations policy and a non-overlap `emailer:operations:run`
  scheduler. Full Doctor always runs when due; retention and external backup
  run only after explicit enablement.
- Adds per-class retention configuration and dry-run/apply coverage for expired
  imports, revoked sessions, completed/dead queue rows, completed delivery
  attempts, Doctor runs, analytics, RSVP, and encrypted backups. Each category
  is capped at 200 records per run. Durable audit, suppression, campaign,
  content, and ambiguous delivery evidence are excluded.
- Adds an off-by-default approved filesystem backup adapter. Its absolute path
  remains external configuration outside release/private roots. Transfers use
  exclusive restrictive files, exact byte count, SHA-256 read-back, and
  redacted evidence. Pruning preserves the newest verified backup.
- Advances candidate identity to `0.4.0`, schema `20260828000000`, and supported
  MariaDB maintenance patches 10.11.19/11.8.9. Deterministic candidate tooling
  declares minimum source `0.3.0` and minimum schema `20260827000000`.

## Schema, dependencies and routes

- Migration: `DoctrineMigrations\Version20260828000000`; forward-only. It
  classifies Doctor runs, adds active finding state, Operations policy/run
  evidence, backup transfer/restore/deletion evidence, and expanded
  housekeeping counts.
- Dependencies: no Composer package or lockfile change.
- New administrative mutations: Owner backup policy and Administrator retention
  policy, both CSRF-protected and deny-by-default; backup policy also retains
  the existing recent re-authentication boundary.
- New CLI: `emailer:operations:run`. There is no new public HTTP route and no
  HTTP restore, migration, release switch, deployment, or arbitrary send path.

## Executed local evidence

- Repository entry checks, secret/private-data scans, and `git diff --check`:
  passed before implementation.
- PHP syntax, shell syntax, formatter, maximum-level PHPStan, Deptrac, and
  custom module boundaries: passed during the aggregate gate.
- Doctrine migrated cleanly to `Version20260828000000`, reported up to date,
  and mapping validation passed on MariaDB 10.11.
- Focused Doctor/policy/external-copy/housekeeping/update regression: 17 tests,
  89 assertions passed on PHP 8.4.24 after the review repair.
- Full PHPUnit: 183 tests and 2,483 assertions passed on PHP 8.4.24 after the
  review repair.
- Production artifact allowlist/inspection, public and deployment-profile
  probes, current/reachable-history secret and private-data scans: passed.
- Deterministic `0.4.0` archive, manifest, checksum, SPDX SBOM, detached
  signature, two-build byte identity, and immutable clean-install tooling
  passed with a disposable test key. No candidate output was committed.
- `emailer:operations:run` command discovery, non-overlap lock, due Doctor
  execution, and stable redacted JSON output passed against the synthetic local
  stack. Its critical result reflected intentionally incomplete development
  recovery/production configuration; no live service was contacted.
- The initial GitHub matrix passed on implementation head
  `7f9aeccd32af7b3be31584e989dd5b2c521fcc4c`: PHP 8.4.24 with MariaDB
  10.11.19, PHP 8.4.24 with MariaDB 11.8.9, and PHP 8.5.9 with MariaDB
  10.11.19.

## Independent review and repair

- An authorized external Claude Sonnet read-only review covered the complete
  Phase 12 diff at implementation head
  `7f9aeccd32af7b3be31584e989dd5b2c521fcc4c` against the verified base. It
  found one medium-severity observability defect: simultaneous readiness-probe
  exceptions shared `probe_unavailable`, so the active finding table's
  primary key could collapse multiple failures. The live sending gate remained
  fail-closed and was not bypassed.
- The finding was confirmed and repaired by deriving a stable, redacted
  per-probe failure code from the probe class. Regression coverage proves two
  simultaneous probe failures remain distinct and stable without exposing
  exception content. Focused Doctor/backup tests passed with 8 tests and 27
  assertions; maximum-level PHPStan and Deptrac passed with zero violations.
- The full aggregate gate passed after the repair, including formatting,
  maximum-level PHPStan, zero-violation Deptrac, migration/mapping validation,
  183 tests with 2,483 assertions, artifact/security scans, deterministic
  disposable-key candidate verification, and clean install.

## Final acceptance and integration

- The authorized external Claude Sonnet delta review covered the finding
  repair through exact head
  `936afeff19e791349f92580bdc5f132d58fba467`. It confirmed the original
  medium-severity finding was closed and found no new confirmed correctness,
  security, privacy, or architecture defect at that head. This was an external
  independent audit, not a GitHub-hosted review.
- All three replacement PR-head CI jobs passed at that exact head: PHP 8.4.24
  with MariaDB 10.11.19, PHP 8.4.24 with MariaDB 11.8.9, and PHP 8.5.9 with
  MariaDB 10.11.19.
- The final operator-signed `emailer-next-0.4.0.zip` candidate was built from
  that reviewed head with the existing externally held signing key and
  independently verified pinned public key. Its SHA-256 is
  `13feaf315b2c20214c6186857e8f811b601bcba197739630484fa137e428c618`;
  the detached Ed25519 signature, manifest, checksum, SPDX SBOM, two-build
  reproducibility, package inspection, immutable clean install, and supported
  `0.3.x` update contract were verified. The pinned public-key SHA-256 remains
  `01a3773c7ea4acb569254e954856924d53b827878477e30f71b21b2a7ee1f61c`;
  no key rotation occurred. Candidate outputs remain outside Git.
- Anton approved PR [#15](https://github.com/Attory/emailer-next/pull/15) at
  exact head `936afeff19e791349f92580bdc5f132d58fba467`. It was squash-merged at
  `2026-08-26T20:24:51Z` as
  `c3c5d0ba5373e46f42183c3fc31264316f512afd`.
- The PR head and squash-merge commit both have tree
  `e4ef0d7c8a72067292b682e4b92c4bdee6f8735e`; the signed candidate therefore
  binds the byte-identical reviewed Phase 12 source tree.
- Post-merge CI run `33010275446` passed the same three PHP/MariaDB jobs on
  exact `main` commit `c3c5d0ba5373e46f42183c3fc31264316f512afd`, including the
  external-egress-disabled test repeat and clean resulting-diff inspection.
  Local `HEAD`, local `main`, and `origin/main` were synchronized and clean at
  ahead/behind `0/0`.
- Phase 12 is accepted as an unpublished, undeployed `0.4.0` update candidate.
  No tag or GitHub Release is required by the Phase 12 stop condition.

## Manual actions and residual limits

- Automation defaults off. Operators must choose deployment/legal periods,
  provision and protect the external mount, schedule the operations command,
  configure journald/Supervisor log retention, and perform isolated restore
  drills. No path, credential, or private data belongs in Git or UI fields.
- A compromised mounted filesystem can deny service or destroy its copies.
  The adapter does not claim immutable/off-site custody; independent storage
  controls remain necessary.
- The local entry audit observed unreachable Git objects left by accepted
  squash merges and authorized branch deletion. No such object is referenced by
  the Phase 12 base or remote refs; no destructive object cleanup was attempted.
- No unresolved critical/high implementation finding is knowingly accepted.
  Publication, client installation/update, deployment-specific retention and
  backup policy, external mount custody, restore drills, TLS/proxy, SMTP/DNS,
  worker supervision, and delivery resumption remain explicit operator
  responsibilities.
