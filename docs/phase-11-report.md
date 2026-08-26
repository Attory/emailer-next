# Phase 11 completion report

## Identity and boundary

- Phase: 11 — Optional RSVP (`0.3.0`)
- Branch: `codex/phase-11-optional-rsvp`
- Verified base: `af0d994d32e47d49df6654d6000c42891f8804f2`
- Repository: `Attory/emailer-next`
- Later scope: Phase 12 Doctor, dashboard, retention scheduling, and backup
  automation expansion are absent.
- Implementation-branch boundary: no merge, tag, publication, deployment,
  client mutation, DNS/provider change, or live SMTP contact was performed
  before exact-SHA approval. The authorized merge and repository-hygiene
  actions are recorded in the final acceptance section; publication,
  deployment, client mutation, and external-provider contact remain absent.

## Implemented outcome

- Added RSVP as an independently off-by-default installation option and an
  explicit draft-campaign option. Approval requires the published template and
  both policy levels to agree, then freezes enablement, a fixed schema, and the
  selected keep-first or replace-latest repeat rule.
- Added dedicated purpose-scoped opaque RSVP links. Delivery and browser-view
  rendering consume the frozen campaign choice. The cookie-free/no-store public
  route accepts only yes/no/maybe, 0–20 guests, and an optional 500-character
  note under a 2 KiB request limit; wrong-purpose, tampered, expired, disabled,
  extra-field, file, and out-of-range requests fail closed.
- Concurrent submissions serialize on the campaign-recipient row and a unique
  response key. Private response storage has no address, IP, user agent, header,
  or raw token.
- Added Auditor aggregate reports and Administrator-only, CSRF-protected,
  recent-reauthenticated, audited private CSV export. Export uses opaque
  recipient IDs and neutralizes spreadsheet formulas.
- Added separate 1–365 day RSVP retention to bounded dry-run/apply housekeeping
  and included private response rows in encrypted backups.
- Advanced runtime/package identity to `0.3.0`. The deterministic candidate
  declares minimum source `0.2.0`, minimum schema `20260826000000`, target
  schema `20260827000000`, and includes a client update manual beside the
  signed download files.

## Schema, dependencies and routes

- Migration: `DoctrineMigrations\Version20260827000000` adds RSVP policy,
  campaign/snapshot state, fixed schema JSON, the RSVP token purpose, private
  response storage, and housekeeping evidence. It is forward-only.
- Dependencies: no Composer package or lockfile change.
- Public route: `GET|HEAD|POST /r/{token}/rsvp`, under canonical HTTPS and the
  existing keyed source/token recipient rate limits.
- Administrative routes: draft-only RSVP configuration (Operator), aggregate
  report (Auditor), and sensitive export (Administrator plus CSRF and recent
  purpose-bound re-authentication). All are registered deny-by-default.

## Executed local evidence

- Composer validation and locked advisory/abandoned-package audit: passed.
- PHP-CS-Fixer, maximum-level PHPStan, Deptrac, and module boundaries: passed
  with zero errors or violations.
- Doctrine migration, up-to-date status, and mapping validation: passed on
  MariaDB 10.11.
- PHPUnit: 177 tests and 2,454 assertions passed on PHP 8.4.24, including the
  new RSVP platform, release/update, authorization, CSRF, and accessibility
  coverage.
- Artifact allowlist build/inspection, public/deployment probes, current and
  reachable-history secret/private-data scans: passed.
- Deterministic `0.3.0` archive/manifest/checksum/SBOM/signature double-build
  and immutable clean-install tooling passed with a disposable test key.
- `git diff --check`: passed.
- Independent Claude Sonnet review found one low-severity campaign-wide RSVP
  lock contention path. It was confirmed and repaired by locking only the
  individual campaign-recipient row while reading the immutable campaign
  snapshot without a locking join. A cross-process regression now holds the
  former shared lock while proving another recipient can submit immediately;
  focused tests and static gates passed.

## Final acceptance and integration

- Independent Claude Sonnet review covered the complete Phase 11 diff, the
  confirmed lock-contention repair, and the final delta through exact head
  `54a1404778feab6a6df4e91c882fbdc13fbc7cac`. No finding remained at that
  exact head. This was an external independent audit, not a GitHub-hosted
  review.
- All three PR-head CI jobs passed at that exact head: PHP 8.4.24 with MariaDB
  10.11.18, PHP 8.4.24 with MariaDB 11.8.8, and PHP 8.5.9 with MariaDB
  10.11.18.
- The final operator-signed `emailer-next-0.3.0.zip` candidate was built from
  that reviewed head with the externally held signing key and independently
  verified public key. Its SHA-256 is
  `79bab9007f8895582ded459b82e8dcbdb9877d7552741fbf01100e31129c758c`;
  the detached Ed25519 signature, manifest, checksum, SPDX SBOM, two-build
  reproducibility, clean install, and supported `0.2.x` update were verified.
  Candidate outputs remain outside Git.
- Anton approved PR [#13](https://github.com/Attory/emailer-next/pull/13) at
  exact head `54a1404778feab6a6df4e91c882fbdc13fbc7cac`. It was squash-merged at
  `2026-08-26T03:10:02Z` as
  `765a7d1e1a3565b65b186c683bc39a28b3d61466`.
- The PR head and squash-merge commit both have tree
  `6cdda9edd1dc3c910931984676be84166b9aeee2`; the candidate therefore binds
  the byte-identical reviewed Phase 11 source tree.
- Post-merge CI passed the same three PHP/MariaDB jobs on exact `main` commit
  `765a7d1e1a3565b65b186c683bc39a28b3d61466`. Local `HEAD`, local `main`, and
  `origin/main` were synchronized and clean at ahead/behind `0/0`.
- The obsolete remote refs `codex/phase-5-contacts-imports-templates` and
  `emailer-mitek` were explicitly authorized for deletion and verified absent
  during the Phase 11 acceptance closeout. Only `origin/main` remained.
- Phase 11 is accepted as an unpublished, undeployed `0.3.0` update candidate.
  No tag or GitHub Release is required by the Phase 11 stop condition.

## Manual actions and residual limits

- A forwarded RSVP link grants its bounded authority for the original campaign
  recipient until expiry/revocation. The UI and records do not claim who
  submitted it.
- No unresolved critical/high local finding is known. Client TLS/proxy,
  external keys, worker supervision, SMTP/DNS, backup custody, publication,
  update execution, and delivery resumption remain operator responsibilities.
