# Ripe 1.0.6 independent review record

Preliminary Claude review used the configured Claude Code 2.1.284 in safe mode,
plan permission mode, and only Read/Grep/Glob tools. No commands, credentials,
production access or writes were authorized. The reviewer warned that the
uncommitted candidate changed during review; a final exact-commit review is
still required.

## Findings independently assessed

- Doctor: normal Doctor remains critical for unconfigured Owner MFA. The offline
  installer parses its structured result and permits only the contract-required
  enrollment finding with `sending_blocked=false`. Other critical findings fail.
- Recovery journal: checkpoint capture and restoration exclude the durable hold
  journal. The decision whether database restoration is necessary is persisted
  before entering recovery, including pre-mutation recovery.
- Resume: hold removal precedes enabling claims in a locked database transaction.
  Any active hold also blocks the normal readiness gate. Regression tests cover
  failed removal under the application identity and malformed persistent holds.
- Crypto: real known-answer vectors from published Light and accepted Ripe 1.0.5
  packages cover both contexts. Baseline Ripe derivation and token prefixes are
  unchanged. Source token keys are decoded and length-verified before mutation.
- Private permissions: protected source files/directories may retain service-group
  read access. Checkpoints, checkpoint keys, receipts and external master keys
  remain owner-only. Group/world write access is refused. Replay tests cover
  0750 directories and 0640 private files.
- Draft approval: exact Light implementation creates snapshots during approval,
  not during initial upload. Inspection checks existing snapshots rather than
  requiring them for drafts. The dedicated owned-draft approval/materialization regression passes
  without creating contacts or list memberships.
- Analytics token email: the published Light issuer requires NULL for analytics
  tokens, matching Ripe. Actual original view/open/RSVP URLs have passed HTTP
  verification after packaged conversion.
- Schema variants: complete Light-derived and native DDL proofs are separate.
  Original Light migration evidence is retained; native ledger rows are an
  explicitly verified baseline. Future migrations must test both physical
  baselines. This does not claim historical Ripe migrations ran on Light.
- Private additions: unfinished-target files are retained in protected quarantine,
  outside restored source private state. Publication and replay are crash-aware.
- Paths: absolute normalized paths and linked ancestors are checked before
  directory creation; recovery/export cannot overlap application, releases,
  private state or key storage.

## Additional rehearsal finding

HTTP route logging retained a recipient token in `request_uri` even though the
route parameter was redacted. Ripe redaction now removes the exact token wire
shape from arbitrary log text, with regression coverage. Synthetic diagnostic
copies have been redacted; original URL material stays in protected fixture
state for compatibility testing.

## Exact first candidate review and bounded repairs

Claude reviewed `86c9784eeaa013eb9ff5075cdadcd39a9a28eda2` read-only with
Read/Grep/Glob only, no commands, writes or paths outside this repository. It
found no new Light conversion blocker and independently verified the preceding
Doctor, hold, crypto, session, permission, quarantine and path repairs. Release
approval remained blocked by the canonical migration gate and pending final
merged trusted-key rehearsals.

Independently assessed findings:

- Native crash rerun: confirmed. An unfinished `release_operation` now refuses
  another update before any new checkpoint, preserving the original complete
  checkpoint for supervised recovery. The regression models committed partial
  DDL and verifies no backup, restore, pointer or completion-status change.
- Old backup retention: confirmed. Automatic pruning excludes Light provenance
  for both local and transferred copies. Explicit operator custody/retention
  decisions remain separate; the recovery set is not automatically pruned.
- Scratch privacy: confirmed. Acceptance verifies the protected installation
  lease and source inventory, then removes the temporary rehearsal tables.
  Partial cleanup can be repeated; protected encrypted recovery assets remain.
- Hold classification race: confirmed. The transport reports a transient failure
  without circuit impact before reading configuration, credentials or opening
  SMTP; it does not claim an ambiguous external send.
- Preparation failure: confirmed safe but inconvenient. The installer now
  restores the original account after verifying unchanged source where possible,
  retains the hold and protected diagnostics, and names explicit recovery if
  preparation recovery cannot complete.
- Recovered-operation package pin: intentional. Recovery and rerun retain the
  exact authenticated operation package; a different same-version package is
  refused. Published version artifacts are never replaced, and the operator
  must not manually delete a hold to evade this binding.
- CI source public key: checksum now pinned in addition to both source archives.
- MariaDB variant uncertainty: all three first-candidate installer/recovery
  matrix jobs passed, including MariaDB 11.8.9 and PHP 8.5.9.

Repairs require new-head tests, matrix CI and read-only review. The unchanged
canonical migration gate remains a separate release blocker.

## Exact repaired-candidate follow-up

Claude reviewed `a52c130a0192ab8f9d80aab01127d9db310b484c` with the same
read-only restrictions. It found no new release blocker, confirmed the repairs,
and identified additional native failure/resume and preparation edge cases.
All three installer/recovery jobs for CI run 38664749932 passed. The local exact
commit disposable-key package also completed the full Light rehearsal.

Further independent assessment and repairs:

- Latest native `rollback_failed` is now refused before another checkpoint or
  operation can conceal it. Delivery resume separately rejects both unfinished
  operations and latest failed recovery, retaining claims and forcing the durable
  accepting-work flag off. The updater's own pre-completion Doctor is unaffected.
- Before schema mutation, recovery verifies unchanged Light without requiring
  idle claims to be fabricated. A durable service-stop flag is recorded only
  after successful quiescence, before stopping services. An active-claim refusal
  does not stop an in-flight source worker. Real claims are never reset.
- A known-unsent hold retains the started attempt as a finished transient record
  and defers even at the configured retry limit; it cannot become an ambiguous
  SMTP outcome. The review's suggested attempt deletion was not adopted because
  this task requires preserving claims/history rather than erasing them.
- Successful recovery also removes its owned rehearsal copy. Foreign scratch
  data is untouched. A new actual-package interruption rehearsal kills acceptance
  after committed partial cleanup and reruns acceptance.
- Native process interruption still requires supervised verification/recovery
  from the retained complete checkpoint. No general downgrade or unchecked
  command to clear failed operation evidence is introduced.

New regressions cover latest failed recovery, resume refusal, unchanged claims,
known-unsent retry-limit outcomes, active-claim preparation refusal, and actual
partial-cleanup replay. New-head review/CI and the final merged trusted-key
release gates remain required; the canonical gate is unchanged.

## Final f2a8865 assessment and native operability follow-through

The exact read-only review of `f2a88651c3ac310fcb49cae0538fff668841b57e` found no
new Light release blocker and verified the hold/claim/preparation/cleanup fixes.
All three installer/recovery jobs in run 38666964800 passed; local exact-package
rehearsal also passed active-claim refusal and interrupted acceptance cleanup.
Three quality jobs remained blocked by the unchanged canonical migration gate.

The review confirmed two native operability gaps, which are repaired rather than
left as an unsupported manual database edit: durable progress now precedes backup
creation, and the existing update command has a package/operation/install-bound
checked recovery action. It verifies unmodified source when no checkpoint exists,
or restores and fingerprints the complete verified checkpoint after mutation,
then validates source boot/mappings/Doctor and append-closes the failed operation.
Partial recovery is replayable, active claims stay intact, and completed update
downgrades are refused. Pre-checkpoint interruption plus checked recovery/forward
retry passed with an actual disposable signed package. Partial-DDL interruption
plus complete fingerprinted recovery/forward retry also passed.

Business-backup restore now preserves authoritative live release history over
older snapshot state and audits that overlay; its every-row/asset drill accounts
for the explicit control-history policy. A regression restores a snapshot with
`started` after live `update_failed` and proves completion evidence and normal
resume are retained. The review's optional retry-budget observation is also repaired: append-only
known-unsent hold evidence retains attempts while preserving the later real SMTP
retry budget and backoff, including after attempt-detail retention. New native code requires its
own final exact-head review and matrix run.

A separate local review identified checkpoint replay after a later native update.
Protected operation custody and latest-operation checks now reject that replay;
the packaged interruption harness attempts the old recovery again after the
successful forward update and verifies the target remains unchanged. This code
requires the next exact-commit independent review and matrix run.
