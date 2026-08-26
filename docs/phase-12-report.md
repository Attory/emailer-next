# Phase 12 completion report

## Identity and boundary

- Phase: 12 — Full Doctor, retention, operational dashboard, and backup automation (`0.4.0`)
- Branch: `codex/phase-12-full-operations`
- Verified base: `e1756ae25b391ac9c8d4fd1c2b60e7c25cc9e13a`
- Repository: `Attory/emailer-next`
- Later scope: Phase 13 Version 1 hardening and acceptance are absent.
- No merge, tag, operator-key signature, publication, deployment, credential or
  DNS change, client-server mutation, provider contact, or live SMTP use was
  performed.

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
- Focused Doctor/policy/external-copy/housekeeping/update regression: 16 tests,
  85 assertions passed on PHP 8.4.24.
- Full PHPUnit: 182 tests and 2,479 assertions passed on PHP 8.4.24.
- Production artifact allowlist/inspection, public and deployment-profile
  probes, current/reachable-history secret and private-data scans: passed.
- Deterministic `0.4.0` archive, manifest, checksum, SPDX SBOM, detached
  signature, two-build byte identity, and immutable clean-install tooling
  passed with a disposable test key. No candidate output was committed.
- `emailer:operations:run` command discovery, non-overlap lock, due Doctor
  execution, and stable redacted JSON output passed against the synthetic local
  stack. Its critical result reflected intentionally incomplete development
  recovery/production configuration; no live service was contacted.
- GitHub matrix CI, independent exact-head review, operator-key candidate build,
  PR identity, and exact head are recorded only after they complete; they are
  not pre-claimed here.

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
  Exact-head review, all matrix CI jobs, operator-signed reproducibility, clean
  install, and supported `0.3.x` update remain acceptance prerequisites.
