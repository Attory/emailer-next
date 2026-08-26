# Phase 10 completion report

## Identity and boundary

- Phase: 10 — View-in-browser and optional privacy-minimized tracking (`0.2.0`)
- Branch: `codex/phase-10-view-tracking`
- Verified base: `7d08cc6e20042fc448657fd87ea6722d91c60e7c`
- Repository: `Attory/emailer-next`
- Later scope: no Phase 11 RSVP route, response state, schema, UI, export, or
  delivery behavior was implemented; Phase 12 operational expansion is absent.
- External action boundary: no merge, tag, publication, deployment, client
  mutation, DNS/provider change, or live SMTP contact was performed.

## Implemented outcome

- Added cookie-free/no-store purpose-scoped browser-view, fixed-pixel, and
  frozen-allowlist click routes. Separate authenticated tokens bind purpose,
  campaign, recipient, contact, key version, and expiry; URLs expose no raw
  identifiers or address.
- View-in-browser is available independently of tracking. Open and click
  tracking are separate Administrator opt-ins, disabled by default, and frozen
  into the immutable campaign approval snapshot with the published template's
  link manifest. Disabled behavior creates no open/click token, pixel, or
  rewritten URL.
- Click requests supply only a bounded index and can redirect only to the exact
  frozen HTTPS link. Wrong-purpose, tampered, expired, disabled, or out-of-range
  requests cannot select another target.
- Added Auditor-readable approximate campaign reporting and
  Administrator-only CSRF-protected future-campaign policy configuration.
  Aggregates store only recipient-ledger identity, event type, link index,
  count, and first/last UTC timestamps. Analytics-token rows omit address; IP,
  user agent, headers, and raw destination URLs are not persisted.
- Added configurable 1–365 day analytics retention to the existing 200-row
  dry-run/apply housekeeping lock and evidence record. Analytics aggregates are
  included in encrypted backups; suppression remains independent.
- Advanced runtime/package identity to `0.2.0`, added a deterministic signed
  candidate builder with minimum source `0.1.0`, and rehearsed clean install,
  supported update, failure, incompatible-schema rejection, and verified backup
  restore. The forward-only schema is not pointer-rollback compatible.

## Schema, dependencies and routes

- Migration: `DoctrineMigrations\Version20260826000000` relaxes only the prior
  forced-off tracking constraint while retaining boolean/RSVP-off checks, adds
  tracking retention, frozen campaign tracking/link state, analytics token
  purposes, nullable address for non-unsubscribe tokens, minimized aggregates,
  and housekeeping evidence.
- Dependencies: no new Composer package and no lockfile change.
- Public routes: `GET|HEAD /r/{token}/view`, `/open.gif`, and
  `/click/{link}`. They share canonical HTTPS enforcement, recipient-only rate
  limits, generic failures, no admin session, no cookie, and no-store headers.
- Administrative routes: `GET|HEAD /admin/analytics` (Auditor) and
  `POST /admin/analytics/policy` (Administrator, CSRF). Both are registered in
  the deny-by-default route policy.

## Executed local evidence

- Complete `composer check`: passed.
- Composer validation and locked advisory/abandoned-package audit: passed.
- PHP-CS-Fixer, maximum-level PHPStan, Deptrac, and custom module boundaries:
  passed with zero violation/baseline/ignore.
- Doctrine migration, up-to-date status, and mapping validation: passed on
  MariaDB 10.11.
- PHPUnit: 174 tests and 2,364 assertions passed on PHP 8.4.24, including
  Phase 1–9 regression, token purpose/tamper/expiry, disabled output,
  frozen redirect, replay/forwarding policy, minimized persistence, retention,
  authorization, accessibility, and update/restore coverage.
- Artifact allowlist build/inspection, representative public/deployment
  probes, current/reachable-history secret and private-data scans: passed.
- Deterministic `0.2.0` archive/manifest/checksum/SBOM/signature double-build
  and immutable clean-install tooling: passed with a disposable test key.
- `git diff --check`: passed.

## Manual actions and residual limits

- The final exact-head candidate must be built with the externally held release
  private key and independently verified public key. Candidate output remains
  outside Git and is not a publication or deployment.
- Exact-head independent Claude review and GitHub CI are required before merge
  approval is requested. Any repair changes the review head and repeats both.
- Open/click observations are approximate bearer activity. Forwarding attributes
  activity to the original recipient and repeated requests increase the bounded
  aggregate; the UI makes no human-read claim.
- No unresolved critical/high local finding is known. Client TLS/proxy,
  external secrets, worker supervision, SMTP/DNS, backup custody, publication,
  and deployment remain operator responsibilities.
