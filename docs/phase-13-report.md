# Phase 13 completion report — Version-1 hardening and acceptance

**Status:** Implementation and working-tree acceptance complete. Not yet
committed, reviewed, signed, merged, tagged, published, deployed, or installed.

## Authority and starting state

- Repository: `Attory/emailer-next`.
- Authorized phase: Phase 13 — Version-1 hardening and acceptance.
- Branch: `codex/phase-13-v1-hardening-acceptance`.
- Exact verified base: `39c8eeccea9866215ce231a08d0e32df9b341199`.
- At phase entry, local `main` and `origin/main` were clean, synchronized, and
  equal to that SHA. Phase 12 PR #15 and acceptance-closeout PR #16 were merged;
  no PR was open.
- The accepted operator-signed `0.4.0` candidate remains outside Git,
  unpublished and undeployed. Its exact-head signing/merge authority does not
  authorize a Phase 13 key or candidate.

The phase re-read the complete master specification, phase jobs, clean-room
checklist, Phase 12 report, architecture/module/data/route documents, complete
threat/security/quality models, release/signing/install/setup/admin/operator/
worker/backup/upgrade/rollback/incident/privacy/development/testing documents,
roadmap/open decisions, and ADRs 0001–0012. Official PHP, Symfony, MariaDB,
Composer, W3C WCAG, and MDN browser-support sources were revalidated on
2026-08-27.

## Implemented Version-1 closure

- Application version advances from `0.4.0` to `1.0.0`; schema remains exactly
  `20260828000000` and no migration is added.
- `emailer:setup:install --complete` is the protected-terminal installation
  alternative. Hidden prompts feed the same database, setup-state,
  organisation, Owner, local-Mailpit, policy, Doctor, and finalization services
  used by web onboarding. No secret-bearing option exists and MFA enrollment is
  deferred to authenticated HTTPS so the CLI never prints enrollment secrets.
- `emailer:secrets:rotate-master-key` now requires active Owner password/MFA
  re-authentication, authentication of the current recovery package/passphrase
  against the live key, delivery quiescence, a verified pre-rotation backup, the
  shared maintenance lock, installation/secret row locks, authenticated full
  re-encryption, atomic restrictive external-key replacement, transactional
  redacted audit, and database/filesystem compensation on failure.
- The production license gate parses `composer.lock`, requires the root
  proprietary declaration, and permits only the documented production-license
  allowlist. Candidate SBOM inspection rejects `NOASSERTION`.
- The `1.0.0` package contract directly accepts the latest declared `0.1.x`,
  `0.2.x`, `0.3.x`, and `0.4.x` lines, targets the existing Phase 12 schema,
  declares conservative restore-based rollback, and includes a standalone
  verifier plus client install/update manuals.
- UI acceptance adds skip navigation, strong two-colour focus, minimum target
  sizing, and reduced-motion handling. The browser matrix and WCAG 2.2 AA
  target explicitly require human keyboard/assistive-technology acceptance.
- Full threat, security, architecture, route, schema, release, installation,
  recovery, operator, testing, quality, roadmap, decision, and support
  documentation is reconciled to the Version-1 boundary.

## Client installation deliverable

[CLIENT-INSTALL-1.0.0.md](CLIENT-INSTALL-1.0.0.md) is the ordered client-server
manual. Its 18 steps cover authority/change records, supported runtime,
least-privilege accounts/paths, MariaDB, independent release-key pinning,
pre-extraction standalone verification, immutable installation, protected
runtime configuration, PHP-FPM/web-server exposure, migrations/preflight,
protected CLI finalization, Owner MFA, encrypted production SMTP configuration,
workers/scheduler/operations, recovery/backup/restore drill, Doctor/exposure/
accessibility acceptance, go-live, failure/rollback, and handover. It contains
no client secret and grants no deployment/provider/DNS/live-SMTP authority.

## Dependencies, migration, schema, and surface

- Composer dependency changes: none.
- Migrations added or changed: none.
- Target schema: `20260828000000`.
- HTTP routes added or changed: none.
- CLI behavior changed: full `emailer:setup:install --complete`; transactional
  `emailer:secrets:rotate-master-key`.
- Generic `emailer:secrets:replace` remains fail closed because transport-secret
  replacement is already the Owner-authorized HTTPS mail workflow.

## Local evidence to date

- License policy: 85 locked production packages accepted; no missing or
  unsupported declaration.
- Focused release/update/accessibility suite: 20 tests, 277 assertions.
- CLI installation integration: 1 test, 16 assertions against disposable
  MariaDB and Mailpit; permanent lock created and submitted passwords absent
  from output.
- Master-key and command coverage includes success, current-recovery-package
  authentication, wrong/outdated recovery rejection, expected-key and
  restrictive-mode checks, active-delivery refusal without backup side
  effects, complete re-encryption, redacted audit, and post-publication
  database/filesystem compensation.
- Symfony service-container lint: pass.
- Maximum PHPStan, Deptrac, and project module-boundary gate: pass with zero
  errors/violations.
- Complete working-tree aggregate `composer check`: pass — 200 tests, 2,652
  assertions; Composer validation/audit/license policy, formatter, PHPStan,
  Deptrac, module/shell/migration checks, Doctrine mapping, production artifact,
  public-path/deployment probes, current/reachable-history secret and private-
  data scans, production-only install, and deterministic `1.0.0` candidate
  tooling all pass. The aggregate will be repeated on the exact committed head.

All executed mail behavior used only the repository's local Mailpit service and
reserved synthetic domains. No provider, live SMTP, DNS, client server,
credential, or external account was contacted or changed.

## Unresolved release prerequisites

- repeat formatter, aggregate, migrations, full PHPUnit, artifact, public-path,
  candidate-tooling, secret, private-data, and reachable-history gates on the
  exact committed head, plus the CI isolated-network negative-egress gate;
- complete self-review and an independent exact-head review with no unresolved
  critical/high finding;
- human supported-browser, keyboard, zoom/contrast, VoiceOver and NVDA
  acceptance recorded by the acceptance owner;
- draft PR, unchanged green required GitHub CI, and exact head SHA;
- separate exact-head authorization for external operator signing and
  independent verification of the deterministic `1.0.0` candidate;
- separate Anton approval naming `Attory/emailer-next`, Phase 13, PR number,
  and full unchanged head SHA before merge;
- post-merge clean synchronization of local `main` and `origin/main`.

No `1.0.0` release archive is committed. No tag, publication, deployment,
client installation, provider contact, DNS change, live SMTP, or production
credential rotation is authorized or performed.
