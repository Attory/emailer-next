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
