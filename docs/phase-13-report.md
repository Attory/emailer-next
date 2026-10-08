# Phase 13 completion report — Version-1 hardening and acceptance

**Status:** The four authorized `0.9.1` walkthrough source repairs pass local
gates on the existing Phase 13 pull request. Current-head CI, renewed independent
review, signing, and human acceptance remain separate gates. Earlier independent-review findings are repaired; the
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

### 0.9.1 walkthrough repair

The authorized repair starts from exact existing PR #17 head
`36dee3ea456e4fccbc25a98902ad4c8df7287ad4`, with a clean working tree,
`origin/main` still at `39c8eeccea9866215ce231a08d0e32df9b341199`, and clean
secret/private-data entry scans. It addresses only the four observed issues:
public-port Apache/Nginx selection/address bindings; fresh-database migration
metadata initialization; authenticated isolated-restore evidence recording;
and obsolete browser footer wording. See
[0.9.1-INSTALLATION-WALKTHROUGH.md](0.9.1-INSTALLATION-WALKTHROUGH.md).

The real restore option compares every contributed row and approved asset in
bounded batches, preserves safety-backup/rollback and quiescence, and issues a
mode-`0600` purpose-authenticated receipt. The interactive CLI recorder verifies
the exact retained artifact, original restore time, and inventory; records
monotonic/idempotent evidence and redacted audit; and never restores live data.
The dashboard uses the latest restore timestamp across backups. No migration,
dependency, HTTP route, sending-gate exception, provider action, or later-phase
behavior is introduced. Earlier unsigned diagnostics cannot clear the warning.

Local repair verification uses PHP 8.4.24/MariaDB 10.11.19 in a newly created,
disposable Compose project, preserving the existing development database:

- Focused regressions: 10 tests, 94 assertions, including the actual
  49-table/asset restore comparison, row-mismatch rollback without a receipt,
  unsafe/tampered/damaged evidence refusal, replay, and an empty restricted
  schema. Maximum PHPStan and all architectural boundaries pass.
- Complete `composer check`: 232 tests, 3,101 assertions; Composer validation,
  audit and 85-package license policy; formatting; static/module/shell checks;
  idempotent migrations and Doctrine mapping; production artifact, secret and
  private-data scans; public/deployment probes; deterministic disposable-key
  `1.0.0` package, supported-source verification, and immutable clean install.
- Separate root-run privilege gate passes: root-owned immutable publication,
  service-key read boundary, and refusal of service-identity code mutation.
- Fresh repaired image on an internal Docker network: external port 25 probe
  fails as required; all 232 tests pass with 8,098 assertions. Assertion totals
  vary with retained synthetic append-only audit rows; test count is stable.
- Compose configuration and `git diff --check` pass. Dependencies, migrations,
  public routes, and production integration settings are unchanged.

The exact committed-head repeat, new SHA, GitHub matrix conclusions, and
disposable acceptance-bundle verification are recorded on PR #17 after push.
No previous SHA's CI or approval is reused. The existing installed acceptance
candidate remains at its previously signed source; this source repair does not
silently change it. Human keyboard/supported-browser/assistive-technology
acceptance, an independently reviewed and operator-signed repaired candidate,
actual clean/mixed-server installation, and a repeated isolated restore using
the authenticated receipt remain required before final acceptance. Mailpit
continues to block production sending; no SMTP/DNS/provider commissioning is
performed.

Repair changed-file inventory:

- Wiring and setup: `config/services.yaml`,
  `src/Module/Setup/Application/DatabaseSetup.php`,
  `templates/setup/policy.html.twig`.
- Backup: `src/Module/Backup/Application/BackupEngine.php`,
  `src/Module/Backup/Application/RestoreDrillEvidence.php`,
  `src/Module/Backup/Infrastructure/OperationalBackupStatus.php`,
  `src/Module/Backup/Presentation/Cli/RestoreBackupCommand.php`,
  `src/Module/Backup/Presentation/Cli/RecordRestoreDrillCommand.php`.
- Candidate tooling: `tools/release/install-0.9.1.sh`,
  `tools/release/build-0.9.1-candidate.sh`.
- Tests: `tests/Integration/Phase13FreshDatabaseSetupTest.php`,
  `tests/Integration/Phase13RestoreDrillTest.php`,
  `tests/Module/Backup/RestoreDrillEvidenceTest.php`,
  `tests/Platform/AcceptanceInstallerWebServerTest.php`.
- Documentation: `docs/0.9.1-INSTALLATION-WALKTHROUGH.md`,
  `docs/CLIENT-INSTALL-0.9.1.md`, `docs/CLIENT-INSTALL-1.0.0.md`,
  `docs/README.md`, `docs/architecture.md`, `docs/backup-restore.md`,
  `docs/data-model.md`, `docs/operator-runbook.md`, `docs/phase-13-report.md`,
  `docs/route-inventory.md`, `docs/security-model.md`, `docs/threat-model.md`,
  `docs/VERSION-1-ACCEPTANCE-CHECKLIST.md`.


### Independent review repair of the walkthrough candidate

After Anton authorized sharing necessary source with Claude, a read-only review
of `2acc17ab92829251c08c9698731b75f4261320fb` requested changes. Its high
finding identified that Apache listener addresses and virtual-host groups are
separate: deriving hosts from listeners can shadow unrelated sites. This repair
starts from that exact unchanged PR head and remains on PR #17.

The installer now reads `DUMP_VHOSTS`, joins existing groups, refuses mixed
wildcard/IP groups, wildcard ports, scoped listeners, or groups with no owned
listener, and verifies unchanged existing default hosts before reload. It
refuses existing Emailer site files and cleans up only its own new site/link
on validation failure. Nginx wildcard IPv6 listeners retain their address family.
The new Apache enabled link sorts after ordinary existing site entries.

Receipt import authenticates and inspects the retained archive without
refreshing `verified_at`. Safety rollback after a failed restore no longer sets
successful restore evidence. Historical successful normal restores retain their
existing cadence semantics; that metric alone does not certify isolation,
comparison, or human approval. Transfer guidance now requires service ownership
and the protected runtime environment.

Verification before commit:

- Complete local aggregate: 235 tests, 3,272 assertions; Composer audit/license,
  formatting, maximum PHPStan, architectural/module checks, shell checks,
  idempotent migrations and mapping, secret/private-data scans, production
  artifact/public/deployment probes, and deterministic signed test tooling pass.
- Root-owned immutable publication and service-key access boundary pass.
- Fresh source-image internal-network suite: all 235 tests pass with 8,269
  assertions; the external port-25 egress probe fails as required.
- Disposable Debian 13 VM with Apache 2.4.68 and Nginx 1.26.3: actual selection,
  rendering, config validation, and reload pass for IP-specific listeners with
  wildcard virtual hosts, wildcard listeners with IP-specific virtual hosts,
  and IPv6-specific groups. The approved synthetic Emailer hostname routes to
  its own document root; legacy host, unknown-host fallback, and unrelated
  loopback Nginx remain unchanged. This exercises web functions with synthetic
  static roots, not the complete signed application installer or human setup.
- Targeted regressions cover unsafe mixed/unreachable/scoped/wildcard-port
  layouts, rendered configuration, default preservation, failed-config cleanup,
  receipt-import freshness, deleted/missing backup refusal, and no success
  timestamp after failed comparison and rollback.

Exact committed-head aggregate, isolated-network negative egress, GitHub matrix,
and renewed Claude conclusions are recorded on PR #17 after push. No earlier
SHA's approval is reused. Operator signing, full clean/mixed installation,
browser/assistive-technology acceptance, and final human acceptance remain
pending. No dependency, schema, public route, production service setting,
provider integration, or later phase is added.

At Anton's explicit request, a non-public `0.9.1` human-acceptance candidate
supersedes the first `0.9.0` walkthrough build before the final Version-1
release decision. It contains the complete
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

No `1.0.0` release archive is committed. This source repair performs no tag,
publication, deployment, client installation, provider contact, DNS change,
live SMTP, or production credential rotation. The previously authorized
`0.9.1` acceptance installation does not authorize those actions for a new SHA.

### Final Apache edge-case follow-up

Claude approved bounded source `be48a8aa3dd43db33df0d013f596c11bd3537bf8`
for signing/rehearsal with no critical/high blockers. A remaining low finding
identified existing Apache main-server content on a listener without any
virtual-host groups. This bounded follow-up refuses that layout before
provisioning and preserves the fallback only for ports with no owned listener.
It adds direct regression coverage, plus the Nginx wildcard IPv6 selection
mapping. Manuals precisely scope failed-config cleanup to site files/link,
document retained Apache modules, and explain service inspection after an
activation/reload failure. Renewed exact-head checks and source review are
required; the earlier SHA's approval does not transfer to the follow-up.

The follow-up's complete local aggregate passes with 237 tests / 3,325
assertions, including the maximum static, shell, migration/mapping, scan,
production-artifact, deployment and deterministic package gates. The final
committed-head repeat, isolation/privilege proofs, newest matrix and renewed
read-only review are recorded on PR #17. No operator key was loaded or signed,
no installed application was changed, and no later phase was implemented.

### Full-installer rehearsal: MariaDB implicit commits

The operator-signed `9fa0e894c1337575161c4c23c0b935b2a8b3afa5` acceptance
installer completed in a disposable Debian 13 Nginx fixture. The first full CLI
setup ran all 13 migrations but immediate key initialization failed with a
missing Doctrine savepoint. A fresh synthetic schema reproduced the failure:
MariaDB DDL had committed implicitly while DBAL still tracked 13 transaction
levels. A retry in a new process succeeded, but that does not satisfy fresh
setup acceptance.

The bounded follow-up disables the misleading all-or-nothing wrapper during
setup migrations and closes the migration connection before schema validation
and key initialization. MariaDB DDL cannot be rolled back as one transaction;
the setup lifecycle lock remains held, failures remain sanitized, and submitted
credentials are still verified before metadata initialization. No migration
file, dependency, schema, or later phase changes. The regression fails before
the repair and checks all 13 persisted versions, immediate key initialization
on the same connection, and actual subsequent transaction rollback after it.

Renewed exact-head aggregate, GitHub matrix, independent review, and operator
signing authorization are required. Earlier signing/source approvals do not
transfer to this repair. The live acceptance installation remains unchanged
during this repair.

The complete local aggregate passes with 237 tests / 3,295 assertions. A separate
fresh-schema diagnostic on the same Debian VM's PHP 8.4.26 / MariaDB 11.8.6
also passes first-attempt key initialization with the repaired source. That
diagnostic loads the repair outside the immutable signed installation and is
not represented as a signed-package installation rehearsal.

### Updater unit: root runtime precedence

Independent review of the supervised deployment procedure identified that
systemd `EnvironmentFile` overrides `Environment`. The runtime file generated
by the installer therefore defeated the updater example's transient cache
assignment. This bounded follow-up applies the runtime override in the
`ExecStart` command with `/usr/bin/env`, after both protected files are loaded,
and updates the manual and platform regression. The root updater uses its
root-only transient directory even when the ordinary runtime file selects the
service cache. No source-version override, update allowlist, migration,
dependency, or later phase changes. Renewed exact-head checks, review and
signing authorization are required; earlier-SHA approvals do not transfer.

The complete local aggregate passes with 237 tests / 3,319 assertions. A
nonmutating actual-systemd probe in the synthetic Debian VM loads the ordinary
runtime file and verifies that the old assignment selects the shared service
cache, while the repaired command selects the private root cache. The probe
constructs the kernel without booting or writing a cache; no installation,
database, key, or private-state change is made by that proof.

## Uploaded template image delivery repair — 2026-10-08

Bounded repair of Phase 13 PR #17, starting from exact PR head
`d4bb5f89a08d4945d129d6a46dd5592f18d66d59` on
`codex/phase-13-v1-hardening-acceptance`. The phase base remains
`39c8eeccea9866215ce231a08d0e32df9b341199`.

Uploaded raster images were stored with relative public paths and those paths
survived into SMTP MIME unchanged. The shared outbound MIME factory now expands
only approved uploaded image `src` paths to the runtime canonical HTTPS origin.
It rejects missing, insecure, credentialed or non-origin canonical URLs when
uploaded images are present and counts this configuration fault toward the
existing transport circuit breaker. Stored versions, approval snapshots, unrelated
links, tracking images, alt text and unsubscribe headers remain unchanged.
The test container uses the same reserved HTTPS origin as the PHPUnit fixture.

Focused synthetic regression checks passed (11 tests, 39 assertions), including
the configured SMTP adapter's actual HTML capture in local Mailpit. Maximum
PHPStan, Deptrac and architectural boundary checks passed. Full aggregate,
production privilege, network-isolation and newest-head GitHub matrix results
are recorded on PR #17 once terminal. The administrator guide now documents
supported merge fields, uploaded image URLs and the current CSS restrictions.

No dependency, migration, route, external-image allowance, styling capability
or later phase is introduced. No production credentials, recipient data or
operator template content are included in this repair or its review. Existing
operator signing/deployment approval names the preceding SHA and cannot cover
this changed candidate. A newly approved signed candidate and a preserving
deployment plan are required before changing the running immutable release.
