# Release 1.0.2 — Newsletter usability implementation record

## Authority and starting state

Anton authorized this bounded release and original design from written
requirements. Phase 13 PR #17 was approved at
`eabd9abb153d1ea91c35b060422db04bfcbc2d7a`, all required checks were verified,
and it merged as `2331a463006b17c2476f3665e2d97762baa0e95c`.
Origin was fetched/pruned and clean main verified synchronized before creating
`codex/1.0.2-newsletter-usability` from that exact base. The release job is
`docs/spec/RELEASE-1.0.2-JOB.md`. No legacy source was inspected or copied.

## Changes

- Responsive newsletter workspace, grouped navigation and useful empty states.
- Guided recipients/content/pace/review composer; CSV preview/commit remains
  explicit. HTML may be uploaded or pasted; rejected text is retained safely.
- Draft editing, preservation of explicitly chosen template versions and
  revision-bound tests/approval, including stale open review-form rejection.
- Owner delivery-limit settings with purpose-scoped password/MFA confirmation.
- Durable campaign pacing alongside installation/domain/concurrency ceilings;
  bounded dispatch and persisted deferrals, reconciled by the minute scheduler.
- Live private count-only progress, pause/resume/cancel and no-JavaScript forms.
- Exact signed 1.0.0 predecessor support, deterministic 1.0.2 packaging and
  complete install/update guides using authenticated target code.

Existing Owner address visibility, list/template actions, inline SMTP
confirmation, unsubscribe protections, secret handling and role boundaries are
preserved. No later phase, external integration, real provider contact or
production change was implemented.

## Dependencies and migration

No dependency or production runtime was added. Twig/AssetMapper and small local
JavaScript modules provide progressive enhancement.

`Version20261008000000` adds nullable campaign pacing, durable window counters
and campaign-test draft revision. Existing campaigns inherit installation
limits; existing draft/review tests are bound to their deployed revision.
Historical test results remain recorded. The supported MariaDB additive DDL is
repeatable after interruption. The package conservatively requires verified
backup restoration for failed updates rather than assuming schema rollback is
safe.

## Local evidence before publication

- Complete `composer check`: passed on PHP 8.4.24 / MariaDB 10.11.19,
  **271 tests / 3,983 assertions**, including Composer advisory/license checks,
  style, static analysis, module boundaries, shell checks, migrations/mapping,
  full tests, production artifact/public paths/profiles, secret/private-data
  scans, deterministic signed tooling and immutable clean install.
- Canonical production privilege gate: passed; root-owned publication and
  service-owned master-key read boundary verified.
- Real Chromium desktop/mobile: draft creation/edit, invalid-input handling,
  identity confirmation cancellation/Escape, live JSON updates, login-HTML
  failure preserving counts, no-JavaScript creation/manual refresh, no
  horizontal mobile overflow, zero external requests and zero JavaScript errors.
- Real synthetic 50-recipient ledger preview: 25 accepted / 50 total / 50%
  progress; fake transport only, visible keyboard focus.
- Independent Claude source review: approved after repairs to stale approval,
  test history/backfill, pacing queue churn, supported-platform guard, accessible
  announcements/contrast and template preservation. Review inputs contained
  source and synthetic fixtures only.
- Upgrade tests cover exact deployed predecessor authentication, success,
  migration failure, health failure and recovery. A real fresh-database test
  verifies retry after partially applied additive DDL preserves suppression.

The draft PR completion record supplies the exact head SHA, commits/files,
network-isolated suite, GitHub matrix, disposable VM upgrade and candidate
checksums after those checks finish. These results must be verified before
requesting exact-head merge approval.

## Manual acceptance and limits

Owner should review the redesigned composer and sending screen before release.
Use `docs/NEWSLETTER-GUIDE.md`. Platform tests and Chromium keyboard/mobile checks
are evidence, not a claim that a human screen-reader session was performed.
Other browser/assistive-technology combinations retain the documented support
matrix in `docs/accessibility-browser-support.md`.

The delivery scheduler remains required for continued dispatch beyond the first
rate window. A processed progress bar is not an inbox-delivery guarantee.
Archived source templates must be replaced explicitly. List membership is
snapshotted at approval, so the final approved audience can differ from a count
seen earlier during review. The existing scheduler limits one reconciliation
batch to 100 campaigns; very large concurrent workloads are outside the MVP
reference load acceptance.

Merge, operator-key signing and preserving production deployment require
Anton's approval for the exact repository/job/PR/head. Disposable-key fixtures
must never be published or deployed to production.
