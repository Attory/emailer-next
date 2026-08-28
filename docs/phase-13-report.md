# Phase 13 completion report — Version-1 hardening and acceptance

**Status:** Implementation and independent-review repair complete. The first
exact-head review rejected `0c02725849cafb04d2ed41d78f8ffd8f2778f931`;
the repaired head requires renewed independent review. Not signed, merged,
tagged, published, deployed, or installed.

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
  re-encryption, restrictive durable old/new-key journaling, atomic external-key
  replacement, transactional redacted audit, maintenance-lock concurrency
  refusal, and automatic pre-/post-commit crash reconciliation.
- The production license gate parses `composer.lock`, requires the root
  proprietary declaration, and permits only the documented production-license
  allowlist. Candidate SBOM inspection rejects `NOASSERTION`.
- The `1.0.0` package contract directly accepts exactly `0.1.0`, `0.2.0`,
  `0.3.0`, and `0.4.0`, targets the existing Phase 12 schema,
  declares conservative restore-based rollback, and includes a standalone
  verifier plus client install/update manuals.
- Client deployment uses a service-owned mode-`0700` key/recovery directory and
  a separate root-controlled release plane. Ordinary web/worker/scheduler
  processes never receive release-write membership; only the root-installed
  one-shot updater retains effective user `emailer` and receives the bounded
  `emailer-release` supplementary group. Reviewed worker, scheduler,
  operations, and update units ship in the signed artifact.
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
- Complete repaired working-tree aggregate: pass — 204 tests, 2,681
  assertions; Composer validation/audit/license policy, formatter, PHPStan,
  Deptrac, module/shell/migration checks, Doctrine mapping, production artifact,
  public-path/deployment probes, current/reachable-history secret and private-
  data scans, production-only install, systemd unit syntax, and deterministic
  `1.0.0` candidate tooling all pass. The artifact/security/candidate segment
  was repeated after its reviewed deployment allowlist changed and passed.

## Independent-review findings and repair

The authorized review of exact head
`0c02725849cafb04d2ed41d78f8ffd8f2778f931` found three high issues: the
documented key parent could not pass effective-user ownership/mode checks, the
service user could not publish an immutable update without an unsafe privilege
choice, and a process crash between filesystem publication and database commit
could leave key/envelope state ambiguous. It also found overly broad source-line
compatibility, inconsistent runtime paths, and unsafe cleanup ownership for a
pre-existing candidate directory.

All are repaired in the same Phase 13 PR. Focused repair evidence is 21 tests
and 194 assertions, including crash before commit, crash after commit,
concurrent-reader refusal, exact accepted-source rejection, and manual/unit
contracts. The old review authorization does not apply to the changed head.

All executed mail behavior used only the repository's local Mailpit service and
reserved synthetic domains. No provider, live SMTP, DNS, client server,
credential, or external account was contacted or changed.

## Unresolved release prerequisites

- repeat the complete aggregate on the exact committed repair head and obtain
  the CI isolated-network negative-egress result;
- obtain renewed authorization and independent review of the new exact head,
  with no unresolved critical/high finding;
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
