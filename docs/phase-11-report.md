# Phase 11 completion report

## Identity and boundary

- Phase: 11 — Optional RSVP (`0.3.0`)
- Branch: `codex/phase-11-optional-rsvp`
- Verified base: `af0d994d32e47d49df6654d6000c42891f8804f2`
- Repository: `Attory/emailer-next`
- Later scope: Phase 12 Doctor, dashboard, retention scheduling, and backup
  automation expansion are absent.
- External action boundary: no merge, tag, publication, deployment, client
  mutation, DNS/provider change, or live SMTP contact was performed.

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
- PHPUnit: 176 tests and 2,438 assertions passed on PHP 8.4.24, including the
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
  snapshot without a locking join; focused tests and static gates passed.

## Manual actions and residual limits

- Exact-head independent Claude review and GitHub CI remain required before
  merge approval. Any repair changes the head and repeats both.
- The final candidate must be built from that reviewed exact head with the
  externally held signing key and independently verified public key. Output is
  outside Git and is not publication or deployment.
- A forwarded RSVP link grants its bounded authority for the original campaign
  recipient until expiry/revocation. The UI and records do not claim who
  submitted it.
- No unresolved critical/high local finding is known. Client TLS/proxy,
  external keys, worker supervision, SMTP/DNS, backup custody, publication,
  update execution, and delivery resumption remain operator responsibilities.
