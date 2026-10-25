# Ripe 1.3.3 campaign and template draft maintenance

Base: 2a8603f460aa2326faa79c570b5e52782c3a1bb6 (published 1.3.2).
Branch: codex/campaign-template-drafts. This is the explicitly authorised
post-version-1 campaign/template usability maintenance; no later phase is added.

Campaigns now uses saved-template choices and an adjacent dedicated Templates
link. A three-choice navigation dialog saves incomplete drafts atomically before
leaving, deliberately discards changes, or stays. No placeholder template or
reusable contact/list is manufactured. Ripe retains existing list workflows and
adds campaign-owned CSV with supported headerless/BOM/column mapping options.
The return link, browser Back and selective choice/security refresh preserve
campaign identity, name, rate and CSV. Live validation/network failures retain
actual File bytes and entered fields; full-page non-JavaScript failures honestly
ask for CSV reselection. Approved immutable content/audience snapshots remain.

Saving drafts does not publish templates, approve campaigns, create snapshots or
queue mail. Review requires a name, a published template and eligible recipients;
all original role, recent-authentication, test and delivery controls remain.
Templates owns HTML import, the corrected image editor/gallery and per-tab draft
recovery. Save buttons and notices use Save template draft. The actual
edition-specific placeholder registry drives examples, insertion and precise
field/line/token/correction diagnostics. Website/Studio/Warm presentation and
newsletter-content editor boundaries remain intact.

## Schema, updates and edition compatibility

Migration Version20261025000000 makes campaign.template_version_id nullable;
converted installation identity advances to schema 20261025000000. Old migrations
and signed source profiles are unchanged. New separate native/converted target
proofs and Light 1.2.6 profiles preserve complete source shape and both Light
ledger rows. Rich 1.2.6 rehearsals include an empty-name/NULL-template draft with
an owned recipient, verified through conversion/recovery and native backup restore.

Native 1.3.2 is added to the existing source roster. Complete source schema/data,
private files, keys and original runtime checkpoints remain required. Recovery
validates the authenticated source executable against its restored schema, rather
than using the new schema verifier on an old schema. Source lock and optional
converted identity tuples are checked before mutation; publication requires one
matching affected identity row plus exact reread. The proven historical 1.0.6
from 1.0.5 lock exception is bounded identically at inspection and publication.
Ordinary backup restore refuses archive/live/runtime schema mismatch before any
mutation; old archives remain verifiable for matching-release isolated recovery.
No general Ripe-to-Light downgrade is added.

## Verification recorded before exact-package acceptance

- Complete local application suite: 442 tests, 6143 assertions, two existing
  root-only skips. New identity binding tests separately passed (3/10).
- Static/architecture/module boundaries, dependency vulnerability/license audit,
  shell, artifact/private-data/exposure and installer contracts passed locally.
- Fifteen retained editor browser cases and sixteen campaign/placeholder cases
  passed using synthetic data, with actual isolated worker/Mailpit MIME and public
  image-byte verification. Campaign actions added no publication, approval, queue,
  delivery attempt or sink message. No contact/list/import was manufactured.
- Actual desktop/mobile screenshots are included in the guide sources; final
  PDFs, source/asset binding and combined private documentation remain gates.
- Independent review drove metadata, recovery, async-editor, file-preservation,
  source identity and NULL-fixture corrections. Final commit-bound review and
  clean-environment usability/package acceptance remain required.

The corresponding public Light 1.2.6 must be accepted and published before the
private CI edition matrix downloads it. Final signed-package/native/conversion
acceptance, reviewed-head CI and Anton's exact PR/head merge approval remain
release gates. No new tag/asset is published at this record. Existing Ubuntu
Apache edition boundary, public ACME limitation and private repository visibility
are retained. Production was not accessed or modified.

## Candidate acceptance follow-up

The Light original-key candidate at 740c53bc1e405acb6d4d9e12c77e2ac51bc85329
passed all eight published predecessor native updates, repeat execution and
completed-operation recovery refusal. Actual process kills during preparation
and after DDL/before ledger were recovered to exact source schema, ledger,
identity and lock, then successfully retried with no new mail.

A disposable Ubuntu 26.04.1 / Virtualmin host passed fresh setup, interruption,
resume/repeat, 31 actual installed-package browser cases and encrypted backup
restoration. Its 195 protected configuration files and five pre-existing main
PIDs remained unchanged; continuous HTTP/HTTPS checks recorded no failure and
SMTP egress remained zero. Public ACME issuance is still not claimed.

Independent manual-only usability passed the new campaign/CSV/template round
trip and five-field diagnostic recovery. Review found a missing Light progress
badge attribute; it is restored with HTTP and live-poll browser assertions in
both editions. The source browser pass now includes 17 campaign cases in
addition to the retained 15 image-editor cases and actual Mailpit delivery check.

Actual setup-file update on the separate synthetic Light125 lab found a second
invocation rejected its new active runtime pointer. Both edition allowlists now
include the exact new handoff paths, with regressions for supported reentry and
continued rejection of unlisted versions, unrelated roots and path traversal.
Final rebuilt packages must repeat these checks before publication. No production
was accessed. These are candidate observations, not final release publication.
