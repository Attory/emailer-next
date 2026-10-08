# Emailer Next 1.0.3 — User confirmation and campaign manual

## Authority and starting state

Anton reported Operator creation returning 403 while testing deployed 1.0.2,
and requested that repair and a basic campaign creation/editing manual.
The bounded job is `docs/spec/RELEASE-1.0.3-JOB.md` under Phase 13 maintenance.
Fetch/prune verified clean main and origin/main at
`de92b08227d83aa096613956d642e390f88166bc`; the branch
`codex/user-confirmation-and-campaign-guide` starts there. The deployed
predecessor source is `4eca16bc7b212a8d35bfe1b6f05f199443a611df`.

## Changes and cause

User creation, update and deletion required a recent user-management
confirmation, but their forms never triggered it. An expired confirmation
therefore threw a generic forbidden page before the mutation handler.

All three existing forms now prompt for the acting administrator's current
password and MFA at submission, using the existing local dialog enhancement.
The fallback fieldset works without JavaScript. Recent confirmation applies
only to user management for five minutes. Invalid or expired confirmation
returns an actionable 422 form; only creation email/role are retained.
Passwords and MFA values are never redisplayed or put into pending state.
Cancel/Escape clears confirmation inputs. Operator is the default new role.

CSRF runs before credential verification; route and service role checks,
Owner invariants, throttling, MFA/recovery consumption, session migration,
audit and private/no-store responses remain enforced. A self-update still
rotates after the user service revokes all target sessions, including a
session freshly confirmed earlier in the same request.

The new authenticated, read-only newsletter guide is linked from Campaigns
and Settings & tools and supports browser printing. The matching Markdown
manual covers recipients/CSV, templates and versions, draft editing, fresh
tests, approval, scheduling, campaign and installation rates, progress,
pause/resume/cancel and troubleshooting. It explicitly distinguishes approval
from starting delivery and SMTP acceptance from inbox placement.

## Dependencies, data and release contract

No dependency, schema migration, permission expansion, sending feature or
later phase is added. Schema remains `20261008000000` (14 migrations).
The immutable 1.0.3 package accepts exactly 1.0.2, declares compatible schema
rollback, retains verified backups, and includes complete installation/update
manuals plus their linked setup/backup/newsletter guides. Historical packages
and their signing identities remain separate from synthetic test fixtures.

## Evidence and acceptance gates

- Synthetic HTTP regression verifies missing/wrong/expired confirmation,
  purpose isolation, MFA and recovery-code reuse, CSRF before consumption,
  session rotation, no-secret redisplay/audit, Create/Update/Delete and
  successful self-update after session revocation. Operator creation by an
  Operator remains forbidden; the guide is authenticated and private/no-store.
- Real Chromium verifies all three mutations, modal focus, Cancel/Escape,
  invalid confirmation with the existing throttle delay, blank secret fields,
  no-JavaScript creation, desktop/mobile guide and print layout. Zero external
  requests and zero JavaScript errors; no horizontal mobile page overflow.
- Synthetic predecessor tests authenticate the actual deployed 1.0.2 source,
  cover success, migration failure and schema-compatible health rollback,
  preserve the 14-row schema ledger and keep delivery quiesced on failure.
- Independent Claude source review passed. Its session note was checked
  against the actual all-session revocation implementation and tested;
  private/no-store assertions and consistent guide wording were added.

The completion record on the draft PR must supply the full current head,
commit/file inventory, terminal aggregate and network-isolated results,
production-privilege checks, GitHub matrix, deterministic disposable-key
candidate record and real VM update/recovery evidence before merge acceptance.
Source-only review and browser fixtures contain synthetic data. No legacy
source, real credential, recipient data or production artifact is introduced.
The canonical secret/private-data/history/artifact and fake-mail isolation
gates remain mandatory. All signature and VM test keys are disposable.

## Manual acceptance and remaining limits

Owner should retry Operator creation after deployment and read the campaign
manual. Other existing sensitive actions retain their current documented
flows. Existing Administrator controls cannot modify Owner accounts; this
repair preserves that authorization. Browser evidence is Chromium desktop,
mobile viewport and no-JavaScript fallback, not a human screen-reader session.

No live provider, customer send, production mutation or operator signing was
performed by this development job. AGENTS.md requires Anton's exact repository,
phase/job, PR and full current head approval before merge. Signing and preserving
deployment of this new patch also require authorization. The earlier 1.0.2
deployment authorization belongs to that completed release.
