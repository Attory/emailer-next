# Phase 7 completion report

**Date:** 2026-08-23

**Phase/job:** Phase 7 — MVP unsubscribe and recipient safety

**Branch:** `codex/phase-7-mvp-unsubscribe`

**Base:** `origin/main` at
`82f2d9f4be706b81cef9669682b465eea7295583`

**Result:** The bounded Phase 7 implementation and local aggregate gates are
complete. Exact draft-pull-request head and GitHub CI results are recorded in
the final handoff and pull request.

## Starting state and scope

Origin was fetched with pruning. Local `main`, `origin/main`, and `HEAD` were
clean and synchronized at the Phase 6 squash merge before this branch was
created. The master specification, phase jobs, clean-room checklist, Phase 6
completion report, relevant ADR, architecture, data, route, threat, security,
privacy, worker, testing, quality, installation, development, and operations
documents were read before implementation. No material conflict was found.

Only unsubscribe tokens, delivery links/headers, public confirmation/POST,
immediate suppression, rate limiting, key versioning/overlap, and bounded token
retention were implemented. There is no view-in-browser, open/click tracking,
analytics, RSVP, Doctor, backup/restore, updater, package, release, deployment,
DNS change, or production-provider contact.

## Implemented bounded scope

- Public bearer tokens use `v<key-version>.<32-byte random handle>.<HMAC>`.
  HMAC input includes purpose, handle, campaign, recipient ledger, contact,
  expiry, and key version. URLs contain no address or internal identifier.
- Random 32-byte token keys are encrypted through the existing secret vault.
  Current-key creation is row-serialized. Rotation creates a new version and
  retains the previous key for the full 365-day token lifetime.
- One retry-safe token row exists per campaign recipient/purpose. Tokens expire
  after 365 days; scheduler maintenance deletes at most 100 rows per run after
  an additional 30-day operational grace. Suppression is never deleted.
- A forwarded token is scoped bearer authority to suppress only its frozen
  recipient. GET displays no address. Repeated POST is idempotent and records
  the same durable global suppression.
- `GET|POST /r/{token}/unsubscribe` runs under a dedicated security-disabled
  firewall before the admin firewall. It does not require or set a cookie,
  bind an administrator session, accept admin CSRF authority, or redirect a
  one-click POST.
- Manual POST accepts only `confirm=unsubscribe`. RFC 8058 POST accepts only
  `List-Unsubscribe=One-Click`. Malformed, tampered, expired, revoked, wrong-
  version, or unavailable tokens fail with generic no-store responses.
- Source and token fixed-window limits use HMAC-derived cache keys, so raw IPs
  and bearer tokens are not cache identifiers. Unsafe HTTP POST fails without
  redirect or mutation.
- Every production message receives a manual HTTPS unsubscribe link in its body
  and `List-Unsubscribe` header. Campaign approval freezes the footer and the
  one-click provider-signing attestation.
- RFC 8058 requires a valid DKIM signature covering `List-Unsubscribe` and
  `List-Unsubscribe-Post`. The latter header is therefore disabled by default
  and emitted only after an Owner, under existing recent reauthentication,
  attests that the configured provider signs both. The external provider
  creates the final signature; Phase 8 Doctor verification remains required.

## Migration, dependencies, and routes

- No Composer dependency was added or updated.
- Forward-only migration added:
  `DoctrineMigrations\Version20260823000000`.
- It adds encrypted token-key metadata/state, private token rows and constraints,
  provider-DKIM attestation, and immutable campaign unsubscribe fields. Existing
  Phase 6 campaign snapshots receive the configured footer with one-click off.
- One public route was added with GET/POST methods. No administration route,
  worker route, tracking route, RSVP route, release route, or deployment route
  was added. The existing Owner-only mail update records the signing attestation.

## Validation evidence

- The migration applied to the upgraded synthetic MariaDB database in eight SQL
  queries. A separate empty MariaDB 10.11 database applied all eight migrations
  through Phase 7 in 71 SQL queries, reported up to date, contained the three
  token tables and three expected unsubscribe/attestation columns, and was then
  removed.
- Focused Phase 7 plus affected Phase 6 regression tests pass 19 tests with 360
  assertions.
- Coverage includes purpose/tamper/key/version/expiry, 365-day overlap, replay,
  forwarding scope, encrypted key storage, retention purge, body/header
  snapshots, DKIM-attestation gating, cookie/session isolation, HTTPS behavior,
  exact RFC/manual bodies, no redirect, generic errors, dedicated limits,
  immediate suppression, and suppression-before-delivery claim.
- PHP-CS-Fixer, maximum-level PHPStan, Deptrac, and custom module boundaries pass
  on the Phase 7 tree.
- Aggregate `composer check` passes 140 tests with 1,952 assertions plus
  Composer validation/advisory checks, PHP-CS-Fixer, maximum-level PHPStan,
  Deptrac/custom boundaries, shell checks, migration/mapping checks,
  secret/private-data/current-history scans, production artifact inspection,
  public-path probes, and non-development platform requirements. GitHub CI is
  recorded after publication of the exact head.

## Security, privacy, operations, and residual risk

- Only generated `example.test` identities and local Mailpit are used. No live
  SMTP, production recipient, credential, DNS, deployment, or external account
  was contacted or changed.
- Application logs, response bodies, queue payloads, metrics/rate keys, and
  audit summaries contain no raw address or bearer token. Operators must redact
  `/r/<token>/unsubscribe` in reverse-proxy access logs.
- Anyone holding a forwarded link can unsubscribe its intended recipient until
  expiry. This is the documented RFC/list-unsubscribe bearer model; it cannot
  affect another recipient and reveals no address.
- Provider DKIM signing happens after the SMTP boundary and cannot be proven by
  message construction. One-click advertising is fail-closed without the Owner
  attestation, and Phase 8 must turn deployment/provider verification into a
  sending-readiness finding.
- Full root/server-operator, database plus external-key, provider-account, and
  process-memory compromise remain outside the application guarantee.

## Phase-boundary evidence

Phase 7 stops after unsubscribe and recipient privacy controls. Phase 8 Doctor,
backup/restore, housekeeping expansion, package/update, and installation work
was not started. View-in-browser/open/click tracking remains Phase 10 and RSVP
remains Phase 11. Do not mark the draft pull request ready or merge without
Anton's explicit approval of the exact repository, phase, pull request, and
full current head SHA.
