# Client upgrade defect repair status

Updated: 2026-10-30. Scope: authorized development/release preparation only.
Production deployment belongs to Emailer Administration; neither client is accessed.

## Ownership and starting state

Repository: Attory/emailer-next. Isolated worktree:
/home/soul/devstation/worktrees/emailer-client-upgrade-repairs
Branch: codex/client-upgrade-repairs
Fetched/pruned origin; verified clean reference main and origin/main both equal
2b1526ec51e42cee0c87d0747ae9d204dafac939 before worktree creation.
No open Ripe PRs at task start. Existing worktrees and release scratch are retained.
Light reference checkout is clean; its separate source/base must be verified before
any Light worktree is created. Use task-storage pinned launcher for owned scratch.

## Findings

Read repository AGENTS.md, canonical specifications, preceding 1.3.3 report,
architecture/security/threat/update/signing guidance and ADRs 0013–0015.
Read administration AGENTS.md, RESUME, backlog, latest Cacao journal and both
handoffs. Hatha's target Doctor exit 2 and automatic recovery are evidenced;
target finding JSON was discarded and must not be described as captured.
Cacao has a confirmed wrong reauthentication return and an unresolved subsequent
sign-out; a redirect-only fix is insufficient. Enrollment start remains POST/CSRF.

## Planned bounded changes and verification

1. Authenticate native offline update maintenance and prove quiescence, then
   require fresh live target health with delivery held. Provide exact signed
   1.0.5 source route, recovery and legacy private-layout handling.
2. Reproduce real DB-session/browser flow including concurrent polling; fix
   continuity plus allowlisted MFA return. Cover fresh/wrong/expired purpose,
   authorization, CSRF, method, converted Owner and existing MFA login.
3. Compact Templates text actions in public Light first, then Ripe; retain
   semantics/accessibility and test desktop/mobile.
4. Focused regressions, affected guides, required gates/CI, independent review
   where available, signed candidates with established custody; never overwrite
   published releases. No merge without Anton's exact repository/PR/head approval.

## Verification and remaining work

Implementation, reproduction, focused tests, CI, PRs and release candidates pending.
No schema/dependency changes yet. No mail sent. No deployment performed.
Final administration handoff must name exact candidate identities, supported
routes, holds, health/recovery steps, retained evidence and remaining gates.

## Checkpoint: one-time route and browser baseline

Anton clarified: one-time 1.0.5 patch only; no future 1.0.5 support commitment.
Implementation in progress targets 1.3.4, binding the original 1.0.5 archive,
source commit and schema. Added operation-bound offline CLI verification draft;
Doctor severity/sending gates are unchanged. Guided 1.0.5 route retains hold.
Recovery handling must not invoke the nonexistent 1.0.5 --read-only option.
These changes are NOT validated or release-ready yet.

Owned pinned task runtime: /home/soul/devstation/tools/task-storage/releases/21ce38e9df3be517f9ab
Task registry: /tmp/k10-emailer-client-upgrades
Task ID: emailer-upgrade-re-e75c2ce544244cdd
Live tool shell session 71829 keeps the task lease; do not adopt other tasks.
Default registry startup waited on another lease and was interrupted before
creation; separate --root uses the same installed ownership/cleanup controls.
Internal owned network explicitly uses 10.250.241.0/24 because default Docker
address pools are exhausted. Browser fixture uses separate 10.250.244.0/24.
No global cleanup or pre-existing container mutations.

Local identity-service baseline: PASS 15 tests, 235 assertions, PHP8.4.24,
MariaDB10.11.19. Cached vendor was regenerated against this source classmap.
Browser sequential baseline proves wrong /admin return but authenticated manual
GET /admin/mfa succeeds. No sign-out cause claimed yet. Deterministic old-cookie
status-poll race reproduction now running. Safe receipts stay in owned work.
Use cached pinned chromium-headless-shell-1217/chrome-headless-shell-linux64/
chrome-headless-shell (ordinary Chromium crashes with this long task TMPDIR).
Earlier fixture preparation failures were missing cached assets / browser startup,
not application regressions. Newly owned services/fixture scratch require cleanup.

## Verified MFA cause and correction

Real browser plus native database session handler reproduces the second defect:
retain the actual old-cookie status request across reauthentication, then release
it after rotation. Baseline: return=/admin; pollChangedCookie=true;
next MFA GET=/login. This is synthetic reproduction, not proof of which exact
request raced on Cacao. The administration report's cookie-race hypothesis now
has a deterministic application reproduction.

Correction: server-owned mfa_management return to admin_mfa; background safe
update/progress responses remove only the session Set-Cookie after Symfony's
session response listener. Revoked IDs remain revoked; no successor session or
authentication grant is transferred. Normal login/reauth/MFA cookie rotation is
retained. Fresh confirmation expiry and next action appear on the MFA page.

Fixed browser: return=/admin/mfa; delayed poll changes no cookie; subsequent GET
retains identity. Complete enrollment via CSRF forms, private acknowledgement,
MFA-enabled account login and MFA-purpose fresh authentication all PASS. No HAR,
secret-bearing screenshot, cookie ID, seed or recovery code retained in receipts.
Focused HTTP/unit: 10 tests/52 assertions PASS, including missing/wrong/expired
purpose, invalid CSRF and method. GET enrollment returns 405/Allow POST in this
fixture (administration's observed 403 rendering is not assumed universal).
Installer non-root baseline:166 tests PASS,57 root-dependent skips; root run pending.

## Exact predecessor custody

GitHub has no v1.0.5 release; two local rehearsal archives have different digests
and cannot replace the original. Read-only strict SSH verified Hatha hostname,
expected IP and unchanged 1.0.5 pointer; signed package and public sidecars alone
are being copied from /var/lib/emailer-candidate into owned test scratch. No
runtime/configuration/key/customer data was accessed and no remote mutation was
performed. This narrowly scoped artifact read supersedes the initial statement
that production was not accessed. Deployment remains Administration-only.

## Gate progress

Exact original1.0.5 package+sidecars copied read-only and authenticated under the
existing Ripe pin. Archive SHA256 matches the handoff. No client mutation.
Static/architecture checks PASS. Full application run:508 tests/6074 assertions,
11 failures:10 missing read-only accepted-history mount in the worktree test
container,1 current-version gate still expecting1.3.3. Corrected the mount and
current candidate gate; affected36 tests now PASS with1030 assertions. Initial
suite's unaffected tests passed; do not misreport the first aggregate as green.
Artifact preparation exposed2 lock-selected dependencies absent from the cached
image. Preparing the exact locked dependency cache in an owned build-only
container; application integration/browser networks retain external isolation.
No dependency or migration changes are introduced by the maintenance patch.

## Checkpoint: first native fixture and controls

Original1.0.5 source fixture installed and enrolled MFA successfully. First
native attempt rejected the old lock format BEFORE mutation: legacy schema_version
is integer1, not a migration timestamp. Added that exact legacy format only in
explicit original1.0.5 one-time context; ordinary sources remain strict. Reusing
this unchanged disposable fixture rather than making another copy.

Pinned storage refused an outside-runner service invocation and then Docker
commands from its detached service (lost process ancestry). Neither refusal was
bypassed. Running the same driver in the original authorized task foreground;
all nested Docker operations retain the task ancestry and resource ledger.
Owned driver: /home/soul/devstation/worktrees/emailer-upgrade-retry.sh.
Synthetic fixture signatures use a disposable key and COPIED sidecars; the
original source archive and all original sidecars remain independently verified
and unchanged. These development fixtures are not final release candidates.

Light reference was fetched/pruned, clean and matched origin/main at
6dbe92367cf33dbcf769790f3ed2e34f34e934ac. Its isolated worktree is
/home/soul/devstation/worktrees/emailer-light-template-actions, branch
codex/template-text-actions. Initial229-test run has one infrastructure failure:
a socket guard hard-codes DB name emailer_test while this isolated DB is named
emailer_light_test; other tests passed. Its release1.2.8 plumbing preserves the
existing schema. Need focused actions/browser, corrected DB-name test execution,
static, security/artifact/CI and signed-candidate checks. No production writes.

## Native production-shaped evidence

Original-source fixture successfully upgraded1.0.5→1.3.4 with APP_ENV=prod,
stopped/absent worker and scheduler, Normal policy, enrolled MFA and delivery
held. Core update passed; the observer initially compared unnormalized baseline
DDL against normalized SQL. Correcting that representation (without changing
expected schema) proves complete target DDL plus77 original business/security/
audit/backup rows, unchanged crypto and zero new emitted messages.

A separate missing-master-key fault immediately after atomic activation passed
complete automatic recovery: exact source schema,77 original row sets, original
root/key and zero sends. The target key was retained privately by the fixture,
then restored from its verified recovery checkpoint; no Doctor check was ignored.
A retained failed-transport item is now included in new production fixtures.
Actual interruption and onward retry acceptance remains pending.

Both editions now pass focused browser actions at1366px/390px, native keyboard
focus, transparent text control styling, and native POST/CSRF interception.
Ripe's skins require an explicit scoped override; no editor/email-canvas styles
change. No POST test/publication is executed by this layout check. Focused sink
and publication/queue/attempt count receipts were added for actions and MFA.

Interruption recovery PASS: actual partial native recipient DDL/ledger observed,
source account fence held, full source restored,78 original row sets including
failed transport preserved, no new messages. The observer handles105's separate
intermediate migration before the later draft migration; arbitrary ledgers still
fail. Need final forward retry and exact signed candidate acceptance.

One-time host completion now records successful completion while retaining an
operational hold even if the old installation accepted work. The original prior
acceptance evidence and campaign states are not rewritten; repeat Finish cannot
resume delivery. Focused installer coverage added. Non-root168 tests PASS,58
root-dependent skips; corrected root test receipt indexing, root rerun pending.

## Draft PR and first CI repair

Ripe draft PR32: https://github.com/Attory/emailer-next/pull/32
Initial head326d2b9eb25c8867df2f7e92326058215ac50686 (confirmed before repair).
Light draft PR15: https://github.com/Attory/emailer-light/pull/15
Initial head2a194f7f395184755c7965dc8048380f562df519 (confirmed before repair).
Final local Ripe suite PASS508 tests/6442 assertions,8 root-dependent skips.

CI correctly blocks the newly updated advisory CVE-2026-45070. Symfony's
2026-10-29 maintainer notice corrects the earlier fix claim:7.4.22 contains the
actual MIME fix. Both lockfiles now change ONLY symfony/mime7.4.17→7.4.22,
within existing7.4 constraints; both update audits report no advisories. Earlier
statements of no dependency change are superseded by this required security fix.
No security/audit control is relaxed. Original historical inputs stay immutable.

Light's first CI failed an obsolete negative guard expecting128 to be rejected.
Updated positive128 coverage and moved the unknown-version negative case to129;
the unlisted-root/edition/traversal negatives remain. Local installer tests pass.
A Composer Flex importmap side effect was preserved as a private patch receipt
and reversed; no unrelated frontend dependency change enters the repair.
