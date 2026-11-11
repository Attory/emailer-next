# Ripe public-release preparation — 11 November 2026

Authorized maintenance scope: public licensing/distribution and the coordinated
Soular Emailer presentation. This is not a new numbered product phase. Base:
`9c4a718eafabf287c2b9576990885bba838e2a8c`; branch:
`codex/ripe-public-release-20261111`. Anton explicitly permitted a new clean
worktree from that exact fetched main while preserving the original stale main,
untracked editor save and all existing worktrees. They were not changed.

## Licensing and distribution

Anton explicitly confirmed Soular controls the necessary first-party rights,
including bundled documentation/artwork/screenshots/templates. This revision
declares AGPL-3.0-or-later, matching Light. The root license, Composer metadata,
README, distribution policy, third-party inventory, runtime notice allowlist and
source footer are coordinated. Dependency licensing restrictions remain unchanged.
All 87 production packages pass the existing policy. The Stimulus MIT notice and
documentation font notice retain their upstream terms. No dependency or migration.

The prospective grant does not relicense every historical version or amend any
prior client agreement. Historical signed assets remain byte-identical. Existing
1.3.9 setup declares its runtime proprietary and has no root LICENSE/notices or
corresponding-source archive; it is unsuitable for reuse as the AGPL download.

The new `v1.3.12-public.1` distribution uses runtime 1.3.12 without adding an
update route. Its separately named fresh-install setup excludes historical
application packages and refuses adoption of earlier installations/journals.
Matching-operation resume remains possible. It includes license/notices and exact
Git source ZIP/URL, bound into the signed installer inventory. Its runtime footer
links to the exact build commit. The original Ripe Ed25519 identity is retained.
See [PUBLIC-INSTALL.md](../PUBLIC-INSTALL.md) and [release procedure](release.md).

## Public-exposure review

The baseline private repository had 27 remote branches, 17 tags, 42 closed PRs,
no open Ripe PR, 3 issue comments, no inline review comments, no submitted PR
reviews, no issue/PR attachments, and no wiki, discussions or Pages. All PR heads
were fetched to a separate audit repository. Current/full-history scans and the
additional-ref history secret/private-data scans passed. No real recipient data,
credential or private signing material was found. Public verification after a
visibility change is still a separate pending operation.

All 329 assets across 17 historical releases (about 1.95 GiB) were matched to
GitHub's recorded SHA-256 digests. Retained local packages and documentation ZIPs
supplied 316 assets; only 24,609 bytes of remaining metadata needed transfer.
Static recursive inspection covered 88 unique archives and extracted text from
88 PDFs, producing 7,431 unique text blobs. The secret scan's manifest findings
were exact SHA-256 inventory values; its remaining three findings match existing
line-specific Symfony false positives and byte-identical retained dependencies.
No scanner control or ignore was relaxed. First-party text address review found
no non-reserved address tokens; public dependency author metadata was classified
separately. Binary artwork is supported by provenance/rights attestation and
synthetic-image records, not a claim of exhaustive automated pixel inspection.

There were 225 Actions runs / 235 attempts: all 219 downloadable log attempts were
retrieved and secret-scanned with no findings (about 54.9 MiB transferred). The
other 16 attempts returned 410 and are unavailable, not claimed inspected. GitHub
reported zero downloadable Actions artifacts. No repository history, release,
attachment or workflow log was rewritten, deleted or replaced.

The GitHub inventory must be rechecked immediately before visibility so a newly
created branch, attachment or workflow output cannot bypass this review.

## Focused verification and remaining rollout

Passed: Composer strict validation; unchanged production dependency-license
policy; changed PHP formatting; PHP/Python/shell syntax; whitespace validation;
six root-isolated tests for fresh install, same-package resume, foreign state and
symlink refusal; exact-commit source-footer rendering; current/all-ref secret and
private-data scans. Tests had no network access and used no SMTP or recipient data.
The first safety-test attempt selected a PHP image without Python and could not
run; the focused test subsequently passed in the existing Python image.

Under Anton's explicit minimum-verification instruction, no aggregate suite,
installer/OS/update matrix, staging environment, manual broad CI rerun or full
package download-back is requested. Preparation commits skip broad automatic CI;
neither repository's main branch currently has protection-required checks. These
skipped checks are not represented as passing. New candidate signature/source,
inventory, deterministic-build and artifact results are recorded on the draft PR
without changing its approved source head.

Stripe's existing restricted live API independently identified Scalar Pty Ltd /
Unbubble in Australia, payments enabled; Payment Links access returned 403. Anton
confirmed eligibility and supplied the dedicated hosted URL. No Unbubble billing
product, account setting, webhook, integration or credential was changed. Hosted
checkout verification showed the expected project, merchant and live-mode AUD
amount entry without a submitted payment. Connected browsers were unavailable;
Anton explicitly authorized existing headless Chromium for bounded verification.

Next: prepare/verify the original-key signed candidate; obtain the exact-head
merge approval for this PR and the Soular PR; verify unchanged heads, merge Ripe,
build final assets from the exact merge source, change visibility only after the
fresh exposure review, and publish the cleared new signed distribution. Verify
anonymous repository/LICENSE/exact source/release access before deploying only
Soular's changed Emailer HTML via its recorded private-backup/atomic-file method.
Then click both live links and check the live page once at a narrow viewport.

No existing Emailer installation is deployed or updated. No later phase, payment
code, new application route, schema, worker, integration or dependency is added.
