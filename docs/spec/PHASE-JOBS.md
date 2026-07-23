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

Stop when fake-SMTP end-to-end sending is reliable and documented. Do not enable public tracking/recipient-action endpoints yet.

---

## Phase 7 — Unsubscribe, tracking, view-in-browser, and RSVP

### Objective

Implement secure public recipient flows.

### Required work

- Purpose-scoped opaque token service with key versioning.
- Manual unsubscribe.
- RFC 8058 one-click unsubscribe.
- Immediate idempotent global suppression.
- View-in-browser token.
- Open tracking and click tracking, independently disabled by default.
- Safe redirect allowlist.
- Optional RSVP module.
- Rate limits and retention.
- No raw email in URLs, logs, or metrics.
- Correct generated headers.
- Public routes isolated from admin sessions.

### Required tests/checks

- Token tamper, expiry, replay, wrong purpose, wrong key version.
- Forwarded-link policy.
- One-click POST without redirect.
- No cookies required for unsubscribe.
- Click open-redirect tests.
- Tracking off means no tracking URLs/pixel.
- Exactly one pixel when enabled.
- RSVP input bounds and injection.
- Suppression enforced after unsubscribe.
- Snapshot generated messages and headers.

### Stop condition

Stop after public recipient flows and privacy controls pass.

---

## Phase 8 — Configuration Doctor, backups, retention, and operations

### Objective

Implement continuous self-checking and operational resilience.

### Required work

- Lightweight Doctor on login.
- Full daily/on-demand Doctor.
- All checks listed in master specification.
- Sending gates for critical findings.
- Redacted report export.
- Structured operational dashboard.
- Backup creation, encryption, manifest, verification.
- Separate recovery-key package.
- CLI restore with preflight and rollback.
- Retention jobs and dry-run.
- Worker/scheduler and backup health.
- Upgrade/check commands.
- Incident, backup, restore, deployment, and rollback runbooks.

### Required tests/checks

- Each Doctor finding and remediation.
- Sending blocked/unblocked correctly.
- Public exposure probes.
- Backup corruption and wrong passphrase.
- Restore drill.
- Suppression preserved.
- Retention boundaries and reruns.
- Disk full, permission, and sink failure.
- Report contains no secret or raw PII.

### Stop condition

Stop when a clean local restore drill and full Doctor pass.

---

## Phase 9 — Release hardening and version-1 acceptance

### Objective

Close all version-1 requirements and produce a release candidate without deploying.

### Required work

- Full threat-model review.
- Full route inventory.
- Dependency/version revalidation.
- Remove development-only artifacts.
- Complete docs.
- CI matrix.
- Security and privacy scans across full history.
- Reproducible artifact.
- SBOM and checksum.
- Upgrade and rollback rehearsal.
- Accessibility review.
- Performance and load test within approved limits.
- Version-1 acceptance checklist.
- No TODO/FIXME in security-critical paths.
- No unresolved critical/high finding.

### Required tests/checks

Run the complete `composer check` and all mandatory safety properties in the master specification.

### Stop condition

Produce a release-candidate report. Do not deploy, push a release tag, rotate production credentials, configure DNS, or contact production SMTP without a separate explicit operator action.
