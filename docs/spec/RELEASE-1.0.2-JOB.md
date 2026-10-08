# Release 1.0.2 — Newsletter usability

Anton authorized this release on 2026-10-08 after approving Phase 13 PR #17
at `eabd9abb153d1ea91c35b060422db04bfcbc2d7a`. PR #17 merged as
`2331a463006b17c2476f3665e2d97762baa0e95c`; clean main was verified equal to
origin/main before creating `codex/1.0.2-newsletter-usability`.

## Bounded scope

- Rebuild an attractive, responsive newsletter workspace and distinguish
  everyday newsletter work from administrative tools.
- Guide composition through recipients, content, sending rate and review;
  retain explicit import preview/commit and test/approval/send boundaries.
- Edit existing drafts and return review-stage campaigns to draft; changes
  invalidate previous tests. Approved content/audience snapshots stay immutable.
- Expose Owner installation delivery limits and per-campaign pacing. Worker
  enforcement must preserve global, domain and concurrent claim ceilings.
- Present automatically refreshed, ledger-derived campaign progress, honest
  accepted/failure/retry/suppression/ambiguous/cancelled counts and existing
  pause/resume/cancel actions, with a working no-JavaScript fallback.
- Improve field labels, validation, preview access, empty states, focus,
  responsive layout and useful campaign next-step instructions.
- Produce a deterministic 1.0.2 clean-install/update candidate supporting the
  deployed signed 1.0.0 predecessor, with migration and recovery evidence.

No legacy code or records may be inspected/copied. Anton authorized original
design from the written requirements and descriptions; screenshots are not a
prerequisite. Retain Twig/AssetMapper and small local enhancements; no new
production runtime or SPA is required. No provider contact or production
change occurs during development. Merge, operator signing and deployment of
1.0.2 require separate exact-head approval.

## Acceptance

Run the canonical complete aggregate, isolation, privilege, artifact, privacy,
security, migration and CI gates; test meaningful rate/concurrency/state races
and draft-test invalidation. Review with Claude using source/synthetic data.
Exercise desktop/mobile and keyboard workflows with real browser interaction,
including automatic progress, errors, retries, no-JavaScript and empty states.
Use synthetic Mailpit recipients only. Retain authenticated no-store reads,
CSRF/role checks, private uploads, durable worker ownership and all unsubscribe
protections. Report migrations, dependencies, exact SHA and unresolved risks.
