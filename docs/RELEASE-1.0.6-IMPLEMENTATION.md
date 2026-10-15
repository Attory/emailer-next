# Emailer Ripe 1.0.6 implementation record

Starting main/origin/main: `3927f17d73190b9e99861a8e3b34b572226665a1`.
Branch: `codex/ripe-1.0.6-light-upgrade` in `/tmp/emailer-ripe-106`.
The primary worktree stays on main; the existing 1.0.4 worktree is untouched.

Status: implementation and isolated verification in progress. No release is
available until the exact signed merged package passes all acceptance cases.

The exact published Light commit is
`b8705dc7ea8b42cea5af5ff89b4f8ed937da193a`. Runtime and corresponding-source
checksums match the operator-provided pins. The contract, schema manifest and
synthetic fixture are preserved verbatim as compatibility evidence. Additional
DDL/trigger proof is generated from the actual published runtime.

## Model and contract coverage

Native Ripe receives only forward migration `Version20261015000000`;
historical migrations remain unchanged. A dedicated Light conversion uses the
published source schema and actual native target structures. A complete target
Light-origin schema proof gates baseline creation. The original single Light
migration row and product identity are preserved in explicit transition fields.
Doctrine baseline rows have zero execution time and represent verified schema
establishment, not claimed historical execution of each Ripe migration.

`recipient_id` is the stable campaign audience identity; nullable `contact_id`
records list provenance. Existing Ripe IDs retain their contact reference.
Light IDs remain recipient IDs with no contacts. Campaign audience provenance
is explicit and immutable. Composite audience/delivery/token foreign keys and
identity guards reject mismatches. Readers, delivery, token issuance, views,
tracking and RSVP use the stable recipient scope. Light draft editing and
approval retain their owned audience. Normal new campaigns use reusable lists.

All contract source table columns are compared through bounded keyed
fingerprints. Source-only administrator singleton metadata is omitted as the
contract specifies. The Light administrator-ready timestamp and setup-event
administrator ID remain intact; native Owner aliases are added separately.
Owner role, High security and empty MFA/reset/contact/list/import structures are
verified. Active Light sessions retain their payload/history and receive
explicit revocation; no recent High grant is imported.

Existing Light secret envelopes and token histories retain their bytes, keys,
purposes, expiry and revocation data. Explicit per-record cryptographic context
metadata selects Light or historical Ripe contexts without decryption fallback.
Light backup records receive explicit edition provenance. A unique global
suppression identity preserves valid historical Ripe case aliases and all
original suppression IDs without merging away their records.

## Safety and recovery

The transition runs under maintenance ownership, a persistent file delivery
hold, database delivery quiescence and persistent fencing of the dedicated
application database identity. Shared identities and active claims are refused.
No accepted delivery or claim is reset. Complete encrypted recovery includes
DDL, triggers, ledger, rows, assets, private state, original runtime environment,
master key and old recovery package. An isolated full restore and schema/data
comparison precede mutation. File and directory publication uses durability
barriers. Recovery retains original ownership and leaves delivery held.

The target runtime environment changes only the explicitly selected Ripe trust
and recovery-package paths. Their original file is recoverable. Staged boot,
mapping, migration and read-only Doctor verification precede atomic activation.
Acceptance is durable before source account access is restored. Explicit normal
readiness and delivery resume remain required. Accepted transitions cannot be
recovered as an edition downgrade.

## Validation and remaining release gates

Local PHP 8.4.24 / MariaDB 10.11.19 full suite passed as the application
identity: 311 tests, 10,108 assertions, no skips after independent-review repairs. An initial root-run suite failed
the existing non-root production-owner assertion and skipped the intended
permission-failure test; rerunning under the correct identity resolved both.
Composer validation, locked advisory audit and the 85-package production license
policy passed. Formatting, PHPStan, architectural boundaries, shell/helper syntax, Compose
validation, secret/private-data history scans, artifact/public/deployment probes
and deterministic signed test-package tooling passed. The unchanged canonical
migration gate failed with Doctrine MigrationConfigurationConflict on a genuinely
fresh disposable database; it has not been bypassed.

Disposable test-key packages have passed these actual runtime/installer cases:

- fresh Ripe 1.0.6 and actual retained-runtime native 1.0.5 update;
- complete native update recovery after a real post-activation missing-key fault;
- published Light full and minimal installation, dry-run and one-invocation
  conversion with complete independent source-column/ID/private-file comparison;
- wrong identity, schema drift, mismatched roots, tampered package, competing
  advisory operation, repeated conversion and accepted downgrade rejection;
- pre-mutation failure, partial committed DDL interruption, interrupted partial
  restoration, automatic post-activation recovery and same-package rerun;
- real old Light cookie invalidation, unchanged password/hash, Owner/High with
  unconfigured MFA, and subsequent ordinary MFA enrollment;
- original active and expired/revoked real-codec URLs, case-insensitive
  suppression, profile fields, assets and actual tracking aggregate updates;
- actual source-serialized accepted/retry envelopes decoded by the target codec;
- retained original Light backup authenticated/restored by unchanged Light code
  into an isolated matching-lock/key clone, followed by packaged conversion;
- conversion-created native Ripe backup restored with the actual Ripe command
  and authenticated every-row/asset drill, preserving owned recipient provenance.

Every conversion/recovery observation recorded zero new attempts and zero sink
messages. A separate explicit post-acceptance delivery resume allowed one
synthetic AUTH-sink verification of the original decrypted SMTP credentials;
that operation left historical recipient attempt fingerprints unchanged.
Prototype packages use disposable keys and are not final release attestations.
The exact candidate and merge artifacts still require matrix CI, independent
exact-head review and final trusted-key package/recovery rehearsals. No tag or
published 1.0.6 artifact exists at this stage.

An automatic approval review rejected removal of the migration check's
`--all-or-nothing` flag. The gate remains unchanged. Operator clarification is
pending concerning nontransactional MariaDB DDL and verified recovery gates.

No production connection or operation has occurred. Light's checkout and
published artifacts remain unchanged. No later release phase is implemented.

## First hosted candidate and review follow-through

Candidate `86c9784eeaa013eb9ff5075cdadcd39a9a28eda2`, PR #22, hosted CI run
38662141095: all three installer/recovery matrix jobs passed (PHP 8.4.24 /
MariaDB 10.11.19; PHP 8.4.24 / MariaDB 11.8.9; PHP 8.5.9 / MariaDB 10.11.19).
All three existing quality jobs failed at the unchanged canonical migration gate.
The read-only exact-candidate Claude review found no new Light conversion blocker
and recommended native unfinished-update refusal plus smaller preservation,
privacy, classification and preparation repairs; see the independent review
record. Those repairs add meaningful partial-DDL, retained-backup and pre-SMTP
hold regressions. Full local suite after repairs passed 304 tests / 9,981
assertions. A new exact-head review and matrix run are required for the repaired
candidate. Final merged trusted-key signing/rehearsals remain blocked by the
canonical gate; there is no released 1.0.6 upgrade at this point.

Second exact review of `a52c130a0192ab8f9d80aab01127d9db310b484c` confirmed the
first repairs and identified additional native failed-recovery/resume and Light
preparation/cleanup edge cases. Assessed fixes preserve attempts and claims,
refuse unsafe reruns/resume, avoid stopping an active worker during refusal, and
remove owned scratch data after recovery as well as acceptance. Focused tests
passed 34 / 895 assertions; full updated suite passed 308 / 10,049 assertions.
The exact a52c130 disposable-key package passed the full local Light rehearsal;
all three a52c130 hosted installer/recovery jobs passed. Subsequent fixes and
partial-cleanup interruption coverage require their own final-head matrix and
read-only review. No production work or final trusted-key signing has occurred.

Final second-review local regression: 308 tests / 10,049 assertions, no skips.
Affected formatting, PHPStan, architecture, shell/helper syntax, full artifact,
secret/private-data and public/deployment checks passed. Original attempts are
retained for known-unsent holds, including at retry limit one; unfinished/latest
failed native recovery cannot be hidden by retry or delivery resume. Package
rehearsals now cover active-claim refusal without worker-stop intent, recovery
scratch cleanup, interrupted acceptance cleanup and overdue scheduled source
work. The next commit's exact-head hosted run and read-only review are the
remaining candidate checks before the unchanged platform release blocker.

Checked native recovery is now executable through the existing update command's
`--recover-native-operation=<UUID>` option. Actual packaged tests killed the
updater before checkpoint creation and after partial committed DDL, then performed
checked source recovery and a normal forward retry without SQL edits. Full local
regression at this stage passed 309 tests / 10,056 assertions. A final additional
retry-budget regression accounts for known-unsent holds through append-only audit
evidence, retaining every attempt and preserving subsequent real SMTP retries.
The final commit requires fresh read-only review and hosted matrix evidence.

Final executable-recovery code passed the full local suite: 311 tests / 10,108
assertions, no skips. Strict static/architectural checks passed. Native updater
and checked recovery also serialize through a server advisory lock, preserving
safety across separate process/Systemd PrivateTmp lock stores. The cross-session
regression proves refusal before an operation row, checkpoint or quiescence.
Known-unsent hold attempts keep append-only credits, preserving subsequent SMTP
retry budget and backoff without deleting any attempt. Final affected artifact
and privacy/security checks and exact-head review/matrix are recorded in PR #22.
The canonical nontransactional-DDL gate remains unchanged and blocked.

Native checkpoint custody is also bound to the latest operation and protected
operation chain. Recovery of a superseded checkpoint is refused before changing
a successfully updated installation. Packaged interruption rehearsals assert
that refusal after the subsequent forward update. The fresh local dependency
audit encountered a Packagist network timeout; it is not recorded as a pass.
Hosted quality jobs run their own locked dependency audit.

Exact 1b926f3 regression passed 311 tests / 10,109 assertions without skips.
Full artifact/security/public-path checks and updater privilege checks passed.
A network-enabled locked dependency audit found no security advisories. The
exact packaged partial-DDL recovery/forward retry and superseded-checkpoint
refusal passed. The early fault fixture lacked the documented prerequisite
verified backup; restored-source Doctor correctly rejected it. That fixture is
corrected without weakening Doctor. The exact review's concurrent-native-writer
and journal-only crash findings are repaired with persistent account fencing
and predecessor-bound recovery; the next exact head requires its own evidence.

## Explicitly approved MariaDB migration-gate correction

Anton explicitly approved the exact command-line flag patch after reviewing the
blocked candidate. Existing terminal CI logs confirm all three quality failures
were Doctrine MigrationConfigurationConflict: all-or-nothing was requested while
Version20261015000000 truthfully declares its MariaDB DDL nontransactional.
Effective installed Doctrine configuration is all_or_nothing=false and
transactional=true. Removing only the CLI override therefore resolves the
incompatibility without globally disabling transactions. Historical migrations,
per-migration transaction defaults and application data transactions are unchanged.
MariaDB implicitly commits DDL; verified complete checkpoints, full schema/ledger
and row/relationship proofs and crash-recovery rehearsals cover that boundary.

The approved patch retains --no-interaction, migration-up-to-date and mapping
validation. schema:validate --skip-sync validates Doctrine mappings only; it is
not a full database/schema comparison. Separate exact physical-schema/foreign-key
and mapped-data checks in native/Light package rehearsals remain mandatory, as
do backup, failure-recovery, zero-send and supported matrix gates. New exact-head
review and all quality/installer CI must pass before merge and trusted signing.

## Routine security maintenance discovered during the approved rerun

The next full dependency audit reported PKSA-xmwy-rmx5-m7h4 /
CVE-2026-108082 in symfony/http-foundation and PKSA-xz3r-9gp4-4m1z /
CVE-2026-109413 in symfony/yaml, both previously locked at 7.4.17. All six
hosted jobs stopped at the image's unchanged Composer audit, before migrations.
The bounded lockfile update moves only those two components to 7.4.21, with zero
new/removed packages and unchanged constraints. Composer audit then reports no
advisories. Audit/install checks and all security controls remain enabled.
Published Light and native predecessor archives are unchanged. The earlier local
full run's one failure was a fixture precondition (database named ripe106_gate_test
instead of required emailer_test); the correct fresh task-only database is used
for the complete rerun, without changing that acceptance assertion.

The security-patched local full Composer pipeline passed, including fresh
16-migration execution, 311 tests / 10,109 assertions, artifact/security/public
checks and a clean audit. CI installer fixture preparation then exposed an
offline-cache assumption: the updated image lacks the historical 1.0.5 lockfile's
7.4.17 archives. A network-disabled reproduction confirmed curl DNS failures
(exit 100), not migration or security-test suppression. CI now prepares only the
historical source subtree's locked dependencies with scripts disabled before
offline artifact construction; no signer or private installation state is
mounted into that connected preparation container. Application and recovery
rehearsals remain on internal networks, and target audits remain mandatory.
Release-facing status wording is conditional on published exact-package evidence
so it remains accurate before and after actual acceptance/publication.

The same missing historical archives also affected the unchanged full
egress-disabled regression suite's accepted predecessor builders (10 Phase 8
failures, exit 100), after the ordinary quality pipeline passed. Its preparation
now fetches exact accepted lockfile archives with download-only, scripts/plugins
disabled and no application/signing/private material mounted. Only the package
download cache is passed to the internal-network test container. No test, source
archive identity, security audit or egress assertion is skipped or relaxed.
