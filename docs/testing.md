# Testing

**Status:** Phase 9 MVP release acceptance coverage over the complete Phase 1–8 behavior.

The stable gate order and executable commands are in
[quality-gates.md](quality-gates.md). Results are recorded in the phase report
without matched secret/private content.

Phase 2 adds focused setup tests under `tests/Module/Setup` and
`tests/Platform/SetupFlowTest.php`. They exercise challenge
entropy/normalization, missing/wrong/expired/replayed proof, filesystem object
and permission rejection, attempt limits, setup routing, session rotation,
HTTPS gating, CLI ownership, installation locks, and guarded reset. All use
generated synthetic values and temporary files; no real credential or recipient
data is accepted.

## Test-data policy

- Use generated synthetic data and reserved domains only.
- Never use a real recipient, message, credential, production token, private
  template, backup, export, log, or screenshot.
- Test secrets are obvious non-production placeholders and remain confined to
  a network-isolated test environment; snapshots/logs must not reproduce them.
- Development and CI can contact Mailpit only. A negative test and CI network
  policy must prove other SMTP destinations are unreachable.
- MariaDB, not SQLite, is used for integration/concurrency equivalence.
- Clean temporary private files after success and failure, then scan generated
  outputs.

## Test layers

- Unit tests for domain transitions, value objects, tokens, crypto envelopes,
  parsing, redaction, and policy.
- MariaDB/Mailpit integration tests for persistence, migrations, mail, queue,
  worker concurrency, and backup/restore.
- Functional HTTP tests for route authorization, CSRF, caching, headers,
  setup/public/admin isolation, and generic errors.
- Static structural accessibility checks cover implemented setup, identity,
  import, campaign, and Doctor templates. Browser, assistive-technology,
  tab-order, and contrast testing require separately recorded evidence.
- Property/fuzz tests for CSV, merge/token parsing, URLs, and malformed input.
- Deployment tests for Nginx/Apache public exposure and immutable artifact
  allowlist.

## Phase 3 coverage

- Secret-envelope round-trip, fresh nonces, tampering, wrong master key,
  installation/type/record/schema AAD, and unsupported versions.
- Omitted, relative, application/release/public, malformed, linked, hard-linked,
  nested-symlink, over-permissive, and unsafe-ancestor master-key handling with
  complete ancestor plus opened/path inode validation.
- MariaDB migration/schema validation and database-runtime identity matching.
- Initial Owner same-password idempotence/wrong-password rejection, explicit
  Argon2id, interrupted/restarted/skipped/repeated TOTP enrollment cleanup,
  encrypted seed, hashed recovery values, and single-use consumption.
- Exact plaintext/unauthenticated local Mailpit persistence and message capture;
  STARTTLS, implicit TLS, auth input, and live-host rejection.
- Submitted plaintext absence from durable database/runtime files and generic
  exception output.
- Failed finalization, lock-publication compensation, repeated/concurrent
  completion, a real delayed-writer/finalizer race under one lifecycle lock,
  one completion event, permanent lock, post-lock setup-row immutability, and
  post-lock 404.
- End-to-end HTTPS wizard coverage without retaining plaintext secret state.

## Phase 4 coverage

- Unauthenticated and all-role route matrix plus exhaustive central-policy
  matching, service-layer escalation denial, and safe-method checks.
- Generic login/recovery failures; account and source progressive throttling,
  lockout, non-extension, expiry recovery, dummy Argon2 identity work, and
  deterministic separate-process atomic reservation.
- Framework session-fixation rotation, HMAC-at-rest identifiers, idle/absolute
  expiry, listing, monotonic tombstone revocation under late-write interleaving,
  logout/reset/role/password/MFA invalidation, and hardened cookie attributes.
- TOTP and recovery authentication, single-use enrollment/login/re-authentication,
  deterministic concurrent replay rejection, restart/confirm/regenerate/disable
  cleanup, and five-minute purpose-bound re-authentication.
- CSRF-negative coverage for every administrative mutation, trusted/untrusted
  forwarded-protocol behavior, hostile Host/forwarded-host rejection, HTTP
  GET redirect/unsafe-method rejection, CSP/HSTS/cache and browser headers.
- Hashed expiring single-use password reset, generic request result, URL-fragment
  token transport, Mailpit capture, completion and replay rejection.
- Real two-process final-Owner demotion, disabling, and deletion races.
- Transactional MFA secret deletion with synthetic rollback proof.
- Audit attribution/source redaction plus database-triggered update/delete rejection.

## Phase 5 coverage

- Conservative recipient comparison, masking, service/route role separation,
  recent-auth full reveal/export, audit redaction, and CSV formula neutralization.
- Headered/BOM and headerless imports, bounded row/column/field limits,
  malformed content, semantic profile validation, file-integrity checks,
  private safe reports, duplicate/suppression counts, commit-time suppression,
  and bounded-memory database staging over a 5,000-row fixture.
- Strict sanitizer/merge/link/header policy, context-aware synthetic rendering,
  immutable versions and publication, and fake-only template tests.
- Raster MIME/dimension/decoder checks, symlink rejection, private quarantine,
  metadata-stripping re-encoding, and random public filenames.
- Exhaustive Phase 5 route registration, all-role read access, and CSRF/method
  rejection for every Phase 5 browser mutation.

## Phase 6–7 coverage

- Durable campaign snapshot/ledger materialization, concurrent uniqueness,
  identifier-only jobs, worker restart/reconciliation, fake transport failure
  classification, bounded retry/rate/concurrency/circuit behavior, pause/resume/
  cancel races, immediate suppression, and accepted-versus-delivered language.
- Recipient-token purpose/version/scope/expiry/tamper authentication, encrypted
  key material, 365-day rotation overlap, replay/idempotency, forwarded-link
  isolation, and bounded expired-row purge.
- Cookie-free/no-store public GET confirmation, exact manual and RFC 8058 POST,
  no redirect, HTTPS fail-closed handling, generic invalid/expired responses,
  dedicated source/token limiting, no raw address in URLs/responses, and a
  suppression-before-delivery claim test.
- Message/body/header snapshots prove a manual URL is always generated and
  `List-Unsubscribe-Post` appears only for the frozen provider-DKIM attestation.

## Phase 8 coverage

- Stable redacted Doctor findings, probe-failure containment, critical sending
  gates, persistent runs, role/method/CSRF coverage, and test isolation.
- Streaming encrypted backup verification, corruption/unsafe-path rejection,
  suppression-preserving restore, safety backup, rollback, approved assets, and
  post-restore quiescence.
- Hidden-passphrase recovery export, wrong-passphrase failure, restrictive
  files, recovery of only a missing master key, and non-overwrite.
- Ed25519 signature, checksum, SPDX, manifest/file digest, source/PHP
  compatibility, unsafe archive path/link, and tamper rejection.
- Bounded idempotent housekeeping evidence and full Phase 1–7 regression.

## Phase 9 coverage

- Deterministic double-build comparison of the `0.1.0` archive, manifest,
  checksum, SPDX SBOM, and detached Ed25519 signature.
- Exact package identity, source commit, source-version window, pinned-key
  permissions, required runtime files, SBOM package licenses, tamper, unsafe
  path/link, and clean immutable install checks.
- Signed `0.1.0-rc.1` to `0.1.0` update, failure, pointer rollback, verified
  restore, schema incompatibility, and disk-reserve rehearsals.
- A 2,000-recipient synthetic campaign materialisation profile that proves the
  configured 1,000-message dispatch batch bound within 45 seconds and 128 MiB
  incremental memory on the reference container. This is acceptance evidence,
  not a client-server throughput promise.
- Static server-rendered accessibility checks for landmarks, language,
  viewport, form labels, table-header scope, visible keyboard focus, responsive
  layout, and the complete campaign approval summary. These source-pattern
  checks do not claim DOM, assistive-technology, tab-order, or contrast testing.

## Phase 10 coverage

- Disabled-output checks prove no open pixel, tracked click URL, or open/click
  token is produced while view-in-browser remains available.
- Purpose, tamper, expiry, forwarding/bearer, and repeat-request behavior is
  exercised against separate view/open/click tokens.
- Redirect tests accept only the immutable published-template HTTPS manifest.
- Analytics storage is limited to ledger identity, event/link counters, and
  first/last timestamps, with bounded dry-run/apply retention.
- Package gates build `0.2.0` for clean install and rehearse the supported
  `0.1.0` update, failure, restore, and schema-rejection paths.

## Phase 1 coverage

Phase 1 implements:

- kernel boot and exact coarse `GET|HEAD /health/live` behavior;
- redaction of sensitive keys, exception detail, address-like values,
  credential assignments, DSN user information, and control characters;
- an allowlist policy that rejects test mail DSNs with non-Mailpit hosts,
  credentials, options, fragments, paths, or a port other than 1025;
- a real Symfony Mailer transport check against loopback-only Mailpit using
  synthetic `example.test` sender and recipient values;
- MariaDB connection, migration execution, up-to-date status, and mapping
  validation;
- production-artifact contents and public exposure probes;
- maximum-level static analysis and module-boundary checks.

The local aggregate suite expects the Compose MariaDB and Mailpit services:

```bash
docker compose run --rm app composer check
```

CI runs the matrix documented in
[ADR 0010](adr/0010-phase-1-toolchain-and-test-isolation.md). It then repeats
PHPUnit with the app, MariaDB, and Mailpit attached only to an internal Docker
network. A negative socket probe to the reserved TEST-NET address
`192.0.2.1:25` must fail before the isolated test run.

## Mandatory safety properties

Every release must prove all fifteen properties in master specification section
22.2, including: no send before setup; no unauthenticated privileged mutation;
CSRF rejection; wrong-purpose token safety; suppression on every send path;
live-SMTP impossibility; output redaction; safe proof-file validation; permanent
setup lock; durable worker recovery/progress; no raw address in public URLs;
private artifact boundary; suppression-preserving restore; and fail-closed key
loss.

Each property maps to named tests and at least one negative case. “Covered by
framework” without a repository test is insufficient for route/role/CSRF,
session, proxy, or serialization behavior.

## Historical Phase 0 evidence

Phase 0 ran no PHP tests because the application did not yet exist. Its required
checks were:

- current-tree high-confidence secret scan;
- full reachable-history high-confidence secret scan;
- current/history private-key header scan;
- current/history recipient-email and sensitive-fixture-extension scan;
- Git object integrity/unreachable-object check;
- `git diff --check`;
- manual review of provenance, refs, complete diff, documentation, migrations
  (none), and manual actions.

See [phase-0-report.md](phase-0-report.md) for the executed command outcomes and
scanner limitations. Phase 1 replaced those fallback expressions with the
pinned scanner and executable platform tests recorded in
[phase-1-report.md](phase-1-report.md).
