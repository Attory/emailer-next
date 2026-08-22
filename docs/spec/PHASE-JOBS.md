# Emailer Next — Bounded Codex Phase Jobs

Use one Codex job per phase. A phase is complete only after review of the result, diff, tests, migrations, and documentation. Never chain phases automatically.

The canonical requirements are in `docs/spec/EMAILER-NEXT-MASTER-SPEC.md`.

---

## Phase 0 — Clean-room foundation and design lock

### Objective

Create the new repository foundation without importing any legacy source or history. Turn the master specification into enforceable architecture documentation and an implementation plan.

### Required work

- Confirm this is a new Git history.
- Add the documentation set required by the master specification.
- Add ADRs for:
  - single-organisation modular monolith;
  - PHP 8.4+ and Symfony 7.4 LTS;
  - MariaDB;
  - `public/` document root;
  - encrypted dynamic secrets with external master key;
  - Symfony Messenger/Doctrine transport;
  - at-least-once delivery semantics;
  - server-rendered Twig/AssetMapper UI;
  - long-running worker plus cron fallback.
- Create a threat model and route inventory design.
- Define module boundaries and dependency rules.
- Define the initial database entity relationship design.
- Define CI and quality gates.
- Add safe `.gitignore`, `.gitattributes`, `.editorconfig`, and placeholder `.env.example`.
- Add a clean-room compliance checklist.
- Do not implement business functionality.

### Required tests/checks

- Current tree secret scan.
- Full reachable-history secret scan.
- Private-key scan.
- PII/fixture scan.
- `git diff --check`.

### Stop condition

Stop after the design and repository documentation are complete. Report open design decisions. Do not scaffold Symfony in this phase unless explicitly included by operator approval.

---

## Phase 1 — Reproducible Symfony platform scaffold

### Objective

Create a reproducible, safe framework baseline with local development and CI.

### Required work

- Scaffold Symfony 7.4 LTS for PHP 8.4+.
- Add Composer lockfile.
- Add Doctrine, MariaDB, Migrations, Security, Mailer, Messenger, Twig, AssetMapper/Stimulus, Validator, RateLimiter, Lock, Monolog, and test dependencies.
- Add Docker Compose development services:
  - PHP application;
  - MariaDB;
  - Mailpit.
- Add one-command safe local bootstrap.
- Add `composer check`.
- Add CI workflow.
- Add structured logging skeleton and redaction processor.
- Configure test environment so live SMTP is impossible.
- Add production artifact allowlist and inspection test.
- Add `/health/live` coarse endpoint with no sensitive detail.
- Do not implement setup wizard, authentication UI, contacts, templates, campaigns, or sending.

### Required tests/checks

- Clean dependency install.
- `composer validate`.
- `composer audit`.
- Static analysis.
- Framework boot test.
- Database migration smoke test.
- Mailpit-only test.
- Artifact exposure test.
- Secret/PII/history scans.

### Stop condition

Stop when a fresh clone reaches a safe local state using documented commands and CI passes.

---

## Phase 2 — Filesystem ownership proof and setup state machine

### Objective

Implement the first-run ownership challenge and secure setup-mode lifecycle, excluding collection of production credentials.

### Required work

- Setup-mode middleware.
- 160-bit Crockford Base32 challenge.
- Fixed `.emailer-install-proof` path.
- Hashed, expiring, rate-limited setup state.
- Symlink and unsafe-permission rejection.
- HTTPS gate after proof.
- Setup session rotation.
- CLI ownership/setup alternative.
- Installation-lock abstraction.
- Setup routes return 404 after lock.
- CLI-only reset command with safety checks.
- Preflight UI and tests.
- No database or SMTP password collection yet.

### Required tests/checks

- Missing, wrong, expired, replayed, and brute-forced challenge.
- Symlink, oversized file, wrong type, unsafe mode.
- Race/concurrent verification.
- HTTP versus HTTPS behaviour.
- Setup lock and reset behaviour.
- Logging contains no challenge code.

### Stop condition

Stop when filesystem proof and setup lock are fully tested. Do not proceed to secrets or SMTP.

---

## Phase 3 — Database setup, master key, Owner account, and SMTP wizard

### Objective

Complete the secure first-run wizard.

### Required work

- Database connection setup and migrations.
- External 32-byte master key file with permission checks.
- HKDF purpose-specific keys.
- Versioned XChaCha20-Poly1305 encrypted-secret envelope.
- Organisation/base URL/time-zone setup.
- Initial Owner account.
- Argon2id password hashing.
- Optional TOTP enrolment and hashed recovery codes.
- Mail transport configuration.
- TLS verification.
- Test connection and test message.
- Encrypted secret persistence.
- Privacy defaults, delivery limits, worker/scheduler detection.
- Final critical Doctor preflight.
- Installation lock completion.
- Secret replacement and master-key rotation CLI skeleton.

### Required tests/checks

- Encryption round-trip, tamper, wrong AAD, wrong key, key version.
- Key permission and missing-key fail-closed tests.
- No plaintext secret in DB, logs, cache, session, queue, or exception.
- Owner account and password hasher tests.
- SMTP test uses fake SMTP only.
- Failed setup resume without plaintext secret state.
- Setup finalization and irreversible lock.

### Stop condition

Stop after secure setup works end to end against MariaDB and Mailpit.

---

## Phase 4 — Identity, roles, admin shell, sessions, and audit

### Objective

Create the secure multi-admin administration foundation for one organisation.

### Required work

- Owner, Administrator, Operator, Auditor roles.
- Login/logout.
- Throttling and generic errors.
- Session database storage, expiry, rotation, list, revocation.
- Password reset.
- TOTP and recovery flow.
- Re-authentication for sensitive operations.
- Central authorization.
- CSRF on all mutations.
- Security headers and trusted-proxy configuration.
- Admin navigation and dashboard shell.
- Append-only audit events.
- Route-by-role matrix.
- User management with invariants preventing removal of last Owner.

### Required tests/checks

- Route matrix.
- Brute force and lockout.
- Session fixation/rotation/revocation.
- CSRF negative matrix.
- Role escalation attempts.
- Last-Owner invariant.
- Generic recovery responses.
- Security-cookie/header assertions.
- Audit redaction.

### Stop condition

Stop before implementing contacts, templates, or campaign business logic.

---

## Phase 5 — Contacts, lists, suppression, imports, and templates

### Objective

Implement private recipient data and content management without sending.

### Required work

- Contacts and custom-field schema.
- Lists and memberships.
- Global suppression.
- Streaming CSV import and mapping.
- Bounded validation and rejection report.
- Safe export with spreadsheet formula neutralisation.
- Template/version model.
- Safe merge-field language.
- HTML allowlist sanitisation.
- Plain-text generation.
- Link validation.
- Safe image upload.
- Preview and archive.
- Synthetic preview data only.
- No live sending; test-message interface remains fake-only.

### Required tests/checks

- Large streaming import memory bound.
- Headered/headerless CSV.
- Duplicate and suppression handling.
- Malformed encoding, control chars, formula injection.
- Path traversal, symlink, MIME mismatch, image limits.
- Template XSS/header injection.
- Unknown merge field.
- Link checker.
- Data authorization and audit events.

### Stop condition

Stop when content and recipient data are ready but no campaign can send.

---

## Phase 6 — Campaign state machine, queue, worker, and mail delivery

### Objective

Implement durable campaign sending.

### Required work

- Campaign lifecycle and authorization.
- Audience snapshot.
- Test-send requirement.
- Approval/scheduling.
- Delivery ledger.
- Messenger Doctrine transport.
- Deterministic Message-ID.
- Worker, heartbeat, graceful shutdown.
- Rate limiting, concurrency, retries, jitter, dead-letter.
- Transient/permanent/ambiguous SMTP classification.
- Pause, resume, cancel.
- Accurate progress from database state.
- Systemd/Supervisor examples and cron fallback.
- Circuit breaker.
- Suppression check immediately before every send.
- One message per recipient.
- No raw PII or secrets in queue payload.
- Production transport configuration and fail-closed TLS/authentication
  validation, without contacting a live provider in development or CI.
- Release-compatible worker quiescence and schema/version metadata needed by
  the Phase 8 updater.

### Required tests/checks

- Concurrent campaign queueing.
- Unique delivery ledger constraint.
- Worker restart and SIGTERM.
- Transient, permanent, timeout, and ambiguous fake SMTP failures.
- Retry bounds and dead-letter.
- Rate limits across multiple campaigns.
- Pause/resume/cancel races.
- Suppression race.
- No duplicate ledger row.
- Honest accepted-versus-delivered language.
- No HTTP route can invoke worker/send directly.

### Stop condition

Stop when fake-SMTP end-to-end sending is reliable and documented and the
production transport can be configured through external secrets. Do not
contact a production SMTP service or enable public recipient-action endpoints.

---

## Phase 7 — MVP unsubscribe and recipient safety

### Objective

Implement the public recipient flow required for a safe MVP release.

### Required work

- Purpose-scoped opaque token service with key versioning.
- Manual unsubscribe.
- RFC 8058 one-click unsubscribe.
- Immediate idempotent global suppression.
- Token rate limits and bounded retention.
- No raw email in URLs, logs, or metrics.
- Correct generated headers.
- Public routes isolated from admin sessions.

### Required tests/checks

- Token tamper, expiry, replay, wrong purpose, wrong key version.
- Forwarded-link policy.
- One-click POST without redirect.
- No cookies required for unsubscribe.
- Suppression enforced after unsubscribe.
- Snapshot generated messages and headers.

### Stop condition

Stop after unsubscribe and its privacy controls pass. Do not implement
view-in-browser, open/click tracking, or RSVP.

---

## Phase 8 — MVP operations, backup, and package/update tooling

### Objective

Make the campaign application safely installable, recoverable, and updateable
on a client-managed server.

### Required work

- CLI/on-demand Doctor checks required to gate sending: application/schema
  compatibility, external key, private/public paths, HTTPS/canonical URL,
  trusted proxies, production SMTP TLS/authentication, sender identity,
  suppression store, worker/scheduler heartbeat, disk/database, and backup
  recency/recovery-key status.
- Sending gates for every critical finding in that MVP set.
- Redacted report export.
- Backup creation, encryption, manifest, verification.
- Separate recovery-key package.
- CLI restore with preflight and rollback.
- Worker/scheduler and backup health.
- CLI-only package inspect/install/update/check commands using immutable release
  directories and an atomic current-release pointer.
- Deterministic package manifest, checksum, SPDX SBOM, and detached Ed25519
  signature verification against an operator-pinned public key.
- Maintenance mode, worker quiescence, encrypted pre-update backup,
  forward-only migrations, health verification, and compatibility-aware
  rollback/restore handling.
- Minimum housekeeping required to prevent unbounded temporary imports,
  sessions, logs, and queue/dead-letter data.
- Incident, backup, restore, installation, deployment, update, and rollback
  runbooks.
- No browser-uploaded package, HTTP-triggered update/migration/switch, or
  unattended update path.

### Required tests/checks

- Each MVP Doctor finding and remediation.
- Sending blocked/unblocked correctly.
- Public exposure probes.
- Backup corruption and wrong passphrase.
- Restore drill.
- Suppression preserved.
- Package tamper, wrong signing key, incompatible version/schema, insufficient
  disk, interrupted update, and failed health-check handling.
- Clean install plus update from a representative previous package fixture.
- Housekeeping boundaries and safe reruns.
- Disk full, permission, and sink failure.
- Report contains no secret or raw PII.

### Stop condition

Stop when clean install/update/restore drills pass locally and the MVP critical
Doctor set can prove or block sending readiness. Do not publish a package.

---

## Phase 9 — MVP 0.1.0 release acceptance

### Objective

Produce a client-installable MVP release candidate without deploying it.

### Required work

- Review all threats and routes implemented through Phase 8.
- Dependency/version revalidation.
- Remove development-only artifacts.
- Complete MVP installation, operation, backup, restore, update, and rollback
  documentation.
- CI matrix.
- Security and privacy scans across full history.
- Reproducible semantic-versioned `0.1.0` archive and deterministic manifest.
- Checksum, SPDX SBOM, and detached signature.
- Clean-server install rehearsal.
- Signed release-candidate update and rollback/restore rehearsal.
- Accessibility review.
- Performance and load test within approved limits.
- MVP acceptance checklist.
- No TODO/FIXME in security-critical paths.
- No unresolved critical/high finding.

### Required tests/checks

Run the complete `composer check`, every safety property required by implemented
MVP behavior, package verification, clean-install, update, restore, and
representative deployment-profile tests.

### Stop condition

Produce the `0.1.0` release-candidate archive and report. Do not publish, tag,
deploy, rotate production credentials, configure DNS, or contact production
SMTP without a separate explicit operator action.

---

## Phase 10 — Update 0.2: view-in-browser and optional tracking

### Objective

Deliver privacy-preserving presentation and analytics as an installable update.

### Required work

- View-in-browser purpose-scoped token.
- Open and click tracking independently disabled by default.
- Safe redirect allowlist fixed at template publication.
- Minimized analytics and bounded retention.
- No raw email in URLs, logs, or metrics.
- A package that updates a supported `0.1.x` installation.

### Required tests/checks

- Token purpose/tamper/expiry/replay and forwarding policy.
- Open-redirect negatives.
- Tracking disabled means no pixel or tracked URL; enabled means exactly one
  pixel and only approved redirects.
- Retention boundaries and privacy/redaction checks.
- Clean install and `0.1.x` to `0.2.x` update, failure, and rollback/restore
  rehearsals.

### Stop condition

Produce a reviewed `0.2.0` update candidate. Do not publish or deploy it.

---

## Phase 11 — Update 0.3: optional RSVP

### Objective

Deliver the optional RSVP module as an installable update.

### Required work

- Purpose-scoped RSVP tokens and bounded public input.
- Explicit enablement and privacy/retention policy.
- Authorized aggregate administration views.
- A package that updates the latest supported `0.2.x` installation.

### Required tests/checks

- Token, authorization, CSRF-isolation, injection, size, concurrency, and
  retention tests.
- Clean install and supported-version update, failure, and rollback/restore
  rehearsals.

### Stop condition

Produce a reviewed `0.3.0` update candidate. Do not publish or deploy it.

---

## Phase 12 — Update 0.4: full Doctor, retention, and advanced operations

### Objective

Complete the operational depth deferred from the MVP.

### Required work

- Lightweight Doctor on login and full daily/on-demand Doctor.
- Every remaining check in the master specification.
- Structured operational dashboard.
- Full configurable retention jobs and dry-run reporting.
- Approved external backup destinations and backup automation.
- A package that updates the latest supported `0.3.x` installation.

### Required tests/checks

- Every remaining Doctor finding and remediation.
- Retention idempotency and boundary tests.
- Backup automation/destination failure and recovery tests.
- Clean install and supported-version update, failure, and rollback/restore
  rehearsals.

### Stop condition

Produce a reviewed `0.4.0` update candidate. Do not publish or deploy it.

---

## Phase 13 — Version-1 hardening and acceptance

### Objective

Close the complete master-specification Version 1 definition and produce a
`1.0.0` release candidate without deploying it.

### Required work

- Full threat-model and route-inventory review.
- Complete Version 1 documentation and acceptance checklist.
- Reproducibility, license, accessibility, load, upgrade, rollback, restore,
  security, and privacy acceptance across the supported release train.
- No unresolved critical/high finding or critical Doctor result.

### Required tests/checks

Run the complete `composer check`, all mandatory safety properties, clean
install, and every supported update path to `1.0.0`.

### Stop condition

Produce the `1.0.0` release-candidate archive and report. Do not publish, tag,
deploy, or contact production services without a separate explicit operator
action.

---

## Post-MVP package rule

Every phase after Phase 9 must produce and test both a clean-install candidate
and a signed update candidate from the latest supported released version. It
must exercise tamper/incompatibility rejection, interrupted-update recovery,
and the documented rollback-or-restore path. A completed development phase does
not itself authorize tagging, publishing, installing on a client server, or
contacting an external provider.
