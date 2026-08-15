# Phase 3 completion report

**Date:** 2026-08-15

**Phase/job:** Phase 3 — Database setup, external master key, encrypted
secrets, initial Owner, and mail configuration

**Branch:** `codex/phase-3-secure-setup-wizard`

**Base:** `origin/main` at
`51a4402fb8f61e9b93b72b29d6b5b8e121b2a034`

**Result:** Phase 3 implementation plus the independent-review repair are
locally passing. Terminal CI for the repaired exact head remains the publication
gate.

## Starting state and canonical inputs

Origin was fetched with pruning. `HEAD`, local `main`, and `origin/main` were
clean and identical to the required base. That commit is the verified squash
merge of Phase 2 PR #3 and contains the Phase 2 implementation, tests, and
completion report. The original pre-squash Phase 2 head is intentionally not an
ancestor of the squash commit.

Repository `AGENTS.md`, all three canonical specifications, the Phase 2 report,
Phase 3-relevant ADRs, and architecture, data, setup, security, threat, route,
installation, testing, quality, development, worker, privacy, backup, incident,
operator, and open-decision documents were read before editing. Every required
canonical document existed.

The operator-approved unrelated `origin/emailer-mitek` branch was not
inspected, fetched as a source, merged, copied, or used.

## Implemented bounded scope

- The HTTPS setup wizard now covers database verification/migration,
  installation identity/key creation, organisation, initial Owner, optional
  TOTP/recovery enrollment, local mail/sender configuration, delivery/privacy
  defaults, capability detection, and critical finalization.
- Submitted database values must match the external Doctrine runtime identity.
  A short-lived second connection verifies the password and MariaDB server;
  the password is neither persisted nor logged.
- The external master-key adapter refuses absent/relative production
  configuration and application/release/public placement. It walks the complete
  existing ancestor chain for links, ownership and modes, snapshots it against
  replacement, then generates 32 random bytes in a mode-`0700` parent and
  mode-`0600` single-link file with bounded stable-handle reads.
- `SecretCipher` derives purpose keys with HKDF-SHA-256 and creates version-1
  XChaCha20-Poly1305 envelopes with fresh nonces. AAD binds installation UUID,
  record type, record UUID, and schema version. Authentication/version failures
  are generic and fail closed.
- The initial individual Owner uses a Unicode-normalized, case-insensitive
  unique email login and explicit Argon2id password hashing. Same-password
  retries are idempotent; an unrelated replacement password is rejected.
  Optional TOTP seeds are encrypted; ten recovery codes are Argon2id-hashed,
  displayed once, and atomically consumable once. Restart/skip removes inactive
  material and at most one applicable TOTP set can exist.
- Mail configuration accepts only plaintext, unauthenticated local
  Mailpit/loopback port 1025. It sends a marked synthetic message to a reserved
  address and stores exactly that host/port/no-encryption/no-auth evidence with
  `tls_peer_verification = 0`. TLS modes, auth values, and live SMTP are rejected
  before transport construction; production transport remains impossible.
- Tracking, click tracking, and RSVP persist disabled. Delivery/import limits,
  global suppression mode, retention inputs, and worker profile are bounded.
  Capability detection checks CLI availability without starting a process.
- Every Phase 3 writer and critical finalization shares one lifecycle lock.
  Writers verify database completion and the filesystem lock are absent before
  mutation. Finalization validates under that same lock, records exactly one
  completion event, records database completion, and publishes the restrictive
  installation lock. Publication failure removes completion evidence;
  concurrent/repeated completion is idempotent and post-lock writes fail closed.
- `emailer:secrets:replace` and `emailer:secrets:rotate-master-key` exist as
  argument-free, non-mutating fail-closed skeletons only.

## Dependencies, migration, schema, and routes

- Composer dependency changes: none.
- `composer.lock` changes: none.
- The aggregate Composer check now establishes and validates migrations before
  PHPUnit so clean-room test databases have the Phase 3 schema. No package
  requirement or resolved dependency changed.
- Migrations added: `DoctrineMigrations\Version20260815000000` and the bounded
  mail-evidence repair `DoctrineMigrations\Version20260816000000`.
- Tables added: `installation_state`, `organisation_settings`,
  `encrypted_secret`, `app_user`, `mfa_credential`, `sender_identity`,
  `delivery_policy`, `privacy_policy`, and `setup_installation_event`.
- Implemented Phase 3 routes: `GET|POST /setup/database`,
  `GET|POST /setup/organisation`, `GET|POST /setup/owner`,
  `GET|POST /setup/mail`, `GET|POST /setup/policy`, and
  `POST /setup/complete`.
- No authentication/admin, recipient, campaign, send, worker, backup, restore,
  release, or deployment route/command was introduced.

MariaDB DDL can auto-commit even when Doctrine requests all-or-nothing
execution. Migration failure therefore blocks setup and requires reviewed
operator recovery; the application never marks a failed migration complete.

## Local validation evidence

Executed successfully before the aggregate publication gate:

```text
docker compose run --rm app composer check:static
docker compose run --rm app composer check:migrations
docker compose run --rm app composer exec phpunit -- --display-all-issues tests/Module/Secrets tests/Module/Identity tests/Integration/SecureSetupPersistenceTest.php tests/Integration/InstallationFinalizerTest.php tests/Platform/Phase3WizardTest.php
docker compose run --rm app composer test -- --display-all-issues
docker compose run --rm app composer check
```

| Check | Result |
| --- | --- |
| Focused Phase 3 repair suite | Pass: 19 tests, 283 assertions |
| Complete Phase 0–3 PHPUnit | Pass: 72 tests, 663 assertions in the final aggregate run |
| PHPStan maximum level | Pass; no baseline or ignored error |
| Deptrac/custom module checks | Pass; zero violation/error |
| Migration/up-to-date/mapping | Pass; Phase 3 migration applied, mappings valid |
| Formatting | Pass; zero fixable files |
| Aggregate `composer check` | Pass |
| Composer validation/audit | Pass; no advisory or abandoned-package failure |
| Secret/private-data scans | Pass for current tree, reachable history, and generated output |
| Production artifact/public probes | Pass |

The first draft-PR CI run (`31881598420`) exposed a clean-room ordering defect:
all three matrix jobs reached PHPUnit before the new Phase 3 tables had been
created. The repair moves the existing migration gate ahead of the existing
test gate in `composer check`; it does not alter application behavior, schema,
dependencies, or the lockfile. The end-to-end wizard fixture now derives its
submitted database identity from the active external DBAL configuration rather
than assuming a fixed test database name. A local run against a separately
created empty database then passed the complete aggregate suite; that temporary
database was removed after validation.

The independent-review repair then tightened four Phase 3 boundaries without
adding a dependency or Phase 4 behavior: exact Mailpit probe/persistence
integrity, explicit external key configuration with full ancestor validation,
one shared writer/finalizer lifecycle lock, and credential-verified Owner plus
cleanup-safe MFA resumption. A new real two-process test delays a mutation while
finalization races for the same lock, then verifies every setup-owned table is
unchanged by attempted post-lock mutations.

## Security and privacy evidence

- Tests cover encryption round-trip, nonce uniqueness, ciphertext tamper,
  wrong key, installation/type/record/schema AAD, and unsupported versions.
- Key tests cover omitted/relative configuration, application/release/public
  placement, malformed files, symlink/hard-link/nested-link escape, unsafe
  file/ancestor modes, and stable ancestor/opened/path metadata. The key value
  is never printed or committed.
- Runtime-generated Owner/MFA secrets are asserted absent from database,
  session/cache/log runtime files, and exception text. Aggregate scanners cover
  the current tree, reachable history, generated output, and artifact.
- Owner-password retry, TOTP restart/skip/repeat, recovery, exact Mailpit capture
  and TLS/auth/live-host rejection, migration, failed/resumed steps, rollback
  compensation, delayed-writer/finalizer concurrency, irreversible lock, and
  post-lock record immutability have executable negative coverage.
- Test identities use reserved `example.test` addresses. No real SMTP
  credential, recipient data, production URL, provider, DNS action, deployment,
  or external account was used.

## Manual actions and unresolved risks

- Production operators must supply database/private/key locations through an
  external runtime mechanism and back up the master key separately. The Phase
  8 encrypted recovery package does not yet exist.
- Phase 3 deliberately permits only Mailpit; production SMTP/provider
  configuration and validation remain unavailable.
- The fail-closed replacement/rotation skeletons cannot recover or rotate a key
  until the authenticated/recovery controls in later phases are implemented.
- MariaDB DDL is not rollback-equivalent to a transactional DML workflow; a
  partial production migration requires reviewed operator recovery.
- Owner MFA is optional during setup. Production health policy/enforcement is a
  later Doctor/authentication concern.
- Aggregate `composer check`, the CI matrix, draft PR identity, and exact head
  SHA are recorded after publication and must pass before merge approval.

## Phase-boundary evidence

No Phase 4 login/logout, authentication firewall/provider, database session,
password reset, re-authentication, admin shell, general user/role management,
or full audit system was introduced. No contact, template, suppression,
campaign, queue message, worker, recipient action, tracking, RSVP, full Doctor,
backup engine, restore, retention job, release, deployment, DNS, or production
SMTP behavior was started.

Stop after Phase 3. Anton's next intervention is approval of the exact draft
Phase 3 pull request and full current head SHA after terminal green CI.
