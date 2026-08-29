# Phase 13 completion report — Version-1 hardening and acceptance

**Status:** Human-acceptance walkthrough repair in progress on the existing
Phase 13 pull request. Earlier independent-review findings are repaired; the
first real `0.9.0` client-server rehearsal exposed installer compatibility and
operator-guidance defects that are now covered by code, tests, manuals, and a
redacted question/answer record. Not merged, tagged, publicly released, or
deployed as `1.0.0`.

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
roadmap/open decisions, and ADRs 0001–0013. Official PHP, Symfony, MariaDB,
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
  processes have no release-write identity. Only independently authenticated,
  frozen target-version code runs in the root-controlled one-shot updater; it
  validates the root-only recorded source against the current pointer and
  publishes recursively root-owned/non-writable releases. Root may read the
  service-owned key for verified backup, but key mutation remains restricted to
  the owning service identity. Reviewed worker, scheduler, operations, and
  update units ship in the signed artifact.
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

The first pushed repair head
`bb9c5516b457d869c87f757d952a65e00cd7c00a` then exposed a fresh-cache CI-only
candidate-tooling defect: the `1.0.0` console could not inspect its own signed
package for the clean-install rehearsal because `1.0.0` is correctly absent
from the predecessor allowlist. The package inspector now permits target-version
self-inspection/clean installation while `ReleaseManager::update` continues to
reject same-version updates. A cleared development cache, focused package/update
suite, and full artifact/candidate gate pass with the explicit predecessor list
unchanged.

The renewed independent review of exact head
`3fced5dc4ed155314c51eb226b444201a3ed1132` found four further high issues:
updater-created releases were still owned by the ordinary application account;
the supported-source matrix used source strings rather than accepted
predecessor artifacts and the real `0.1.0` updater rejected the combined
manifest; journal/key namespace changes lacked containing-directory durability
barriers; and the update manual installed root configuration from an archive
before signature verification. It also found systemd parsing weakened the
documented `TRUSTED_HOSTS` regex and the installer omitted its promised
pre-lock summary/safe remediation categories.

The second-review bounded repair makes the target-version runtime—not predecessor
binaries—the production updater, authenticates it before extraction or root
installation, requires an exact source/current-pointer match, and runs it under
the root-controlled unit so releases remain root-owned and immutable. Tests
archive all four exact accepted predecessor commits. A root-run gate proves a
fresh `emailer` process cannot chmod published code and proves the constrained
root read of the service-owned key. Master-key namespace changes now cross
explicit directory `fsync` barriers with failed-barrier rollback coverage. The
manual preserves `TRUSTED_HOSTS` backslashes, and the CLI renders a non-secret
configuration summary plus categorized safe remediation before permanent lock.
Focused repair evidence passes at 25 tests and 304 assertions. The complete
working-tree PHPUnit suite passes at 210 tests and 2,775 assertions; artifact,
candidate, root/service privilege, systemd syntax, formatting, static analysis,
shell, whitespace, and private-data gates also pass. The final exact committed
aggregate and new-head CI evidence remain to be recorded.

The first CI run on the second-review repair passed the aggregate and new
root-privilege gate in all three matrices, then exposed one clean-image harness
gap: exact predecessor archives require the checkout's Git objects, while the
production-style image correctly excludes `.git`. The external-egress-disabled
test container now exposes only the already checked-out Git metadata at a
dedicated read-only history path; `/app/.git` remains absent, the image continues
to exclude repository history, and a platform assertion locks both halves of
that boundary.

The subsequent Claude Code review of exact head
`de6b559df71980190be5873e195f5237f4bde879` correctly found that those source
archives were not security inputs to the updater: the runtime trusted the
root-recorded version and pointer basename without authenticating installed
predecessor bytes. It also found that initial master-key creation and recovery
flushed but did not file-`fsync` their staged contents, unlike replacement and
rotation writes.

This bounded repair requires the complete signed predecessor package and four
original sidecars as a distinct updater input. The target runtime verifies the
historical package contract under the pinned key, restricts legacy manifest
handling to exact same-version source evidence, and compares every installed
file and digest before quiescing delivery or creating a backup. The integration
matrix now builds actual runtime artifacts from each exact accepted integrated
source tree, packages and signs them with a disposable key, installs their
manifest-bound contents, and consumes those packages through the production
target-updater path. Tampered evidence, installed drift, source/pointer mismatch,
and approved-target mismatch fail before operational mutation. These fixtures
are deliberately not represented as externally held historical operator-signed
outputs.

All initial, recovered, replacement, rotation-journal, and rollback key-file
writes now share a fail-closed file-durability abstraction after `fflush` and
before publication; directory barriers remain after namespace mutation. Tests
prove creation and recovery invoke the barrier and a failed barrier leaves no
published key or staging residue. [ADR 0013](adr/0013-authenticated-predecessor-and-durable-publication.md)
records the provenance, privilege, legacy-contract, and durability decisions.
The repaired working tree passes the complete aggregate at 219 tests and 3,038
assertions, and the same 219 tests with 8,025 assertions on the
external-egress-disabled network, the
maximum-level static/architecture gates, deterministic candidate tooling,
artifact/secret/private-data inspection, root/service privilege gate, and
systemd/shell checks. The isolated predecessor builders use only the identical
locked Composer tree and an image-retained copy of the already-installed,
lock-selected import-map dependencies. This also exercises clean checkouts where
the ignored development cache is absent; the predecessor builds perform no
network fetch. Exact committed-head CI
and renewed Claude Code review results remain to be recorded.

All executed mail behavior used only the repository's local Mailpit service and
reserved synthetic domains. No provider, live SMTP, DNS, client server,
credential, or external account was contacted or changed.

## 0.9.0 human-acceptance walkthrough repair

The first client-server rehearsal is recorded, with all secrets removed, in
[0.9.0-INSTALLATION-WALKTHROUGH.md](0.9.0-INSTALLATION-WALKTHROUGH.md). Every
question and failure from candidate location and sidecars through DNS, web
server routing, MariaDB credential roles, setup proof, canonical origin, MFA,
Mailpit, policy, Doctor, backups, and re-authentication is answered there and
cross-linked from the rewritten [CLIENT-INSTALL-0.9.0.md](CLIENT-INSTALL-0.9.0.md).

The repaired installer now:

- provisions required packages noninteractively and preserves existing package
  configuration;
- detects and configures either Apache or Nginx, with HTTP-to-HTTPS redirect,
  the exact `public/` document root, and PHP-FPM routing;
- preserves an already supported MariaDB installation and runs
  `mariadb-upgrade` through the authenticated administrator channel only when
  an older installation required upgrade;
- stages signed-package verification outside a root-only home directory;
- places the one-time setup proof in an external service-owned private path
  rather than the immutable release;
- generates an acceptance-only hostname certificate with `CA:FALSE`; and
- labels every database credential role and the external DNS prerequisite.

Setup and administration pages now explain the restricted application database
credential, canonical HTTPS origin, authenticator-generated TOTP code, Mailpit
acceptance boundary, policy fields, irreversible completion, Doctor refresh,
backup destination/schedule, and same-browser backup re-authentication. The
ambiguous phrase “fresh password” is removed: the form requests the current
Owner password and current MFA proof, states that it does not create or change
a password, records the purpose for five minutes in the same browser session,
and returns directly to Backups. Missing or expired backup re-authentication
redirects to that form with guidance instead of an unexplained generic 403.

Doctor scheduling also invalidates successful evidence older than installation
completion, so pre-setup findings do not persist for a full operational
interval. Focused repair evidence passes at 30 tests and 954 assertions. The
complete local aggregate passes at 222 tests and 3,100 assertions, together
with Composer validation/audit/license policy, formatting, maximum static and
architecture analysis, shell and migration checks, Doctrine mapping,
production artifact build, secret/private-data and public-path scans,
deployment probes, and deterministic signed-candidate tooling.

## Unresolved release prerequisites

At Anton's explicit request, a non-public `0.9.0` human-acceptance candidate is
produced before the final Version-1 release decision. It contains the complete
Phase 13 application plus a signed-package-verifying root installer so the
manual browser and assistive-technology checks can run against an installed
system. This acceptance build does not replace or weaken the `1.0.0` release
contract. Its added builder, installer, documentation, and platform contract
pass the complete local aggregate at 220 tests and 3,053 assertions.

- obtain the required GitHub CI matrix, including its isolated-network
  negative-egress repeat, on the exact pushed repair head;
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
