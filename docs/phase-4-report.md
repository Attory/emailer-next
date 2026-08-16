# Phase 4 completion report

**Date:** 2026-08-16

**Phase/job:** Phase 4 — Identity, roles, admin shell, sessions, and audit

**Branch:** `codex/phase-4-identity-admin-sessions-audit`

**Base:** `origin/main` at
`f9131497462390c44f4f0c56a4ef9be37683f8a7`

**Result:** The bounded Phase 4 implementation, focused suites, and complete
local aggregate gate pass. Terminal GitHub matrix results are recorded on the
draft pull request's exact head.

## Consolidated security repair

The draft branch received one bounded security repair beginning at exact head
`970bf45ba03e86b6f3acf9ce47cc2fc1de7d6e5d`. The repair adds forward-only
`Version20260818000000` without rewriting the Phase 3 or original Phase 4
migrations. It preserves monotonic session tombstones across expiry, rotation,
revocation, and user deletion; makes TOTP counters single-use under lock; makes
login and re-authentication reservations atomic with bounded pending work,
explicit success/failure finalization, and deterministic multi-process
concurrency proofs.

Password-reset links now use only the stored canonical HTTPS origin, never the
request host, while the raw token remains exclusively in the fragment and POST
body. Production host matching is explicit, forwarded hosts are ignored,
cookies are always Secure, safe plaintext identity/admin requests redirect to
the canonical origin, and unsafe plaintext methods are rejected before session
or credential processing. One shared character-aware, locally blocklisted
password policy covers initial Owner setup, user creation, change, and reset.
Missing/disabled login identities perform production-cost dummy Argon2id work;
MFA/recovery verification workload is no longer password-short-circuited and
uses deterministic matching-work-factor dummy proofs; known
and unknown recovery requests both perform bounded local Mailpit delivery.

Anonymous recovery events have no initiating actor, route authorization denials
include redacted source attribution, and user deletion transactionally removes
MFA credentials plus encrypted seeds. A synthetic database failure proves the
whole deletion rolls back. No test sends reset material in a query string.

The mandatory second Opus review of exact intermediate head
`74ac0ac8ed94c29a859ed334a1deabf36ffb1b2d` found an unauthenticated session
storage-exhaustion path. Login inputs are now bounded before hashing or session
storage, anonymous identity pages have a source limit before session access,
revocation erases payloads, expired tombstones are retained for seven days and
then reclaimed, and stale throttle rows are removed opportunistically outside
the reservation transaction. Failed-login audit targets use only an opaque
account HMAC, authority-ceiling denials retain source HMAC attribution, and
safe admin HEAD requests match the route policy. Existing malformed canonical
origins now block the repair migration with an explicit operator error; an
absent pre-installation origin remains valid.

The subsequent exact-head Opus review of
`adeca631d9c585a8367c305812d5731d0feead87` identified HEAD and blocked-POST
paths around the anonymous limiter. Safe identity and re-authentication methods
now share the side-effect-free branch, GET/HEAD and POST login attempts consume
the source limiter before session access, and already-blocked attempts return
429 without adding session or append-only audit rows. The transition into
lockout is recorded once. Session garbage collection is indexed and batch
bounded, throttle cleanup runs only after a successful reservation and ignores
retryable cleanup conflicts, setup/runtime origin checks agree, and proxy
guidance requires replacement rather than appending of client forwarding data.

The exact-head Opus review of
`d00a0430344e237f53df883230109c43b8e4f1d8` then identified that Symfony could
restore a supplied session cookie before the controller/authenticator limit and
that reset-request CSRF validation could likewise start a session first. One
kernel-request subscriber now limits every login and password-reset method
after the transport-security gate but before the firewall. Forged-cookie HEAD,
login POST, and reset POST regressions prove blocked traffic creates no session
row. Expired account and re-authentication locks now reset their failure window
at the documented five-minute boundary, and throttle reclamation has a covering
index.

The mandatory Opus review of exact head
`7ef9ff2a18a9e548716bdf077703f0e2f99f3cfb` identified that Symfony decodes
percent-encoded paths before routing while the early transport, identity-limit,
and administrative default-deny gates compared the encoded path. All three
gates now apply the same single `rawurldecode()` as the router. Encoded login
regressions prove transport rejection and pre-session limiting, while an encoded
admin route proves denial auditing remains active.

The repaired focused suite passes with 23 tests and 594 assertions. The complete
local suite passes with 95 tests and 1,286 assertions; the rebuilt-image,
external-egress-disabled repetition passes the same 95 tests with 5,536
assertions. Clean installation applied all five migrations, and a separate
schema upgraded through `Version20260817000000` before applying only
`Version20260818000000` (eight SQL statements); both paths passed mapping
validation. A credential-bearing stored origin was separately proved to stop
the migration until repaired. Composer
dependencies and `composer.lock` remain unchanged.

## Starting state and canonical inputs

Origin was fetched with pruning. `HEAD`, local `main`, and `origin/main` were
clean, synchronized, and exactly the required base. PR #4 was verified merged
at that commit. The required branch did not exist and was created directly from
the verified base.

`AGENTS.md`, all canonical specifications, the Phase 3 completion report, and
the relevant ADR, architecture, module, data, security, threat, route,
installation, testing, quality, operator, admin, setup, incident, privacy,
backup, release, and development documentation were read before implementation.
Every required canonical document existed and no material scope conflict was
found.

## Implemented bounded scope

- Individual accounts reuse the Phase 3 `app_user`/`mfa_credential` model and
  support exact Owner, Administrator, Operator, and Auditor roles. There is no
  self-registration or shared credential.
- Symfony authentication uses the Doctrine account provider, explicit Argon2id
  password verification, optional TOTP or one-use recovery proof, generic
  public failures, and account/source HMAC-keyed throttles with progressive
  delay and bounded lockout.
- `RoutePolicyRegistry` defines every `/admin` route's permitted methods,
  minimum role, and sensitive classification. A path-based subscriber fails
  closed for absent policies, while identity/audit application services repeat
  their own authorization checks.
- MariaDB server sessions use framework-generated IDs stored only as
  purpose-separated HMACs, 30-minute idle and 12-hour absolute deadlines,
  durable revocation, source/agent HMACs, list/revoke UI, fixation rotation, and
  late-response protection that cannot resurrect a revoked row.
- Password recovery uses a random 256-bit value, UUIDv7 selector, SHA-256 hash
  at rest, 30-minute expiry, single-use transaction, generic requests, dual
  rate limits, and all-session invalidation. The raw value is delivered only
  through local Mailpit in a URL fragment and is submitted in a CSRF-protected
  POST body, not an HTTP request target.
- MFA management supports restart-safe enrollment, encrypted TOTP seeds,
  hashed codes, one-time display/consumption, confirmation, disable, and
  recovery regeneration. Password, MFA, role, successful re-authentication,
  and other authority changes rotate or invalidate applicable sessions.
- Sensitive user, role, password, session, and MFA changes require a
  purpose-bound five-minute re-authentication assertion. Every browser mutation
  is CSRF-protected and uses an unsafe method; GET/HEAD remain safe.
- Owners and Administrators manage users within their authority ceiling.
  Database row locks preserve at least one active Owner under concurrent
  demotion, disabling, and deletion attempts.
- The server-rendered shell supplies dashboard, profile, session, MFA, user,
  and redacted audit navigation. Explicit trusted proxies, host-only
  always-Secure/HttpOnly/SameSite-Lax cookies, CSP, HSTS-on-secure,
  no-sniff/frame/referrer/permissions headers, and no-store identity/admin
  responses are enforced.
- Audit events store actor, action, opaque target, UTC timestamp, hashed source
  where applicable, result, and bounded redacted JSON. Application validation
  rejects secret/address/control-bearing summaries and MariaDB triggers reject
  update/delete independently.

## Dependencies, migration, schema, and routes

- Composer dependency and lockfile changes: none.
- Migration added: `DoctrineMigrations\Version20260817000000`, forward-only.
- Existing `app_user` gains the four-role constraint and lifecycle timestamps.
- Tables added: `user_session`, `login_throttle`, `password_reset_token`, and
  `audit_event`; append-only audit triggers are added.
- Public identity routes: `GET|POST /login`, `GET|POST /password-reset`, and
  `GET|POST /password-reset/{selector}`.
- Administration routes: dashboard, POST-only logout, re-authentication,
  profile/password, session list/revoke, MFA status/start/confirm/disable/
  recovery, user list/create/update/delete, and audit list.
- No contact, list, import, suppression, template, campaign, delivery, queue,
  tracking, unsubscribe, RSVP, backup, restore, release, deployment, production
  SMTP, or Phase 5 schema/behavior was introduced.

## Local validation evidence

Focused commands executed successfully:

```text
docker compose run --rm app php bin/phpunit tests/Integration/Phase4IdentityServicesTest.php tests/Platform/Phase4AccessTest.php
docker compose run --rm app composer check:static
docker compose run --rm app composer check:migrations
docker compose run --rm app php bin/phpunit
git diff --check
```

The original Phase 4 implementation suite passed with 17 tests and 271 assertions. It
covers route/role/default-deny policy,
authentication, both throttle scopes, sessions, recovery delivery, MFA,
re-authentication, CSRF/method safety, trusted proxy/header/cookie policy,
service escalation, final-Owner concurrency, audit append-only enforcement,
and raw-sensitive-value absence. Complete Phase 0–4 PHPUnit passes with 89
tests and 963 assertions. Aggregate `composer check` passes: strict Composer
validation/audit, zero PHP-CS-Fixer changes, PHPStan, Deptrac with zero
violations, custom module boundaries, shell checks, migrations/mapping, all
tests, current/history secret and private-data scans, production artifact
build/inspection, and public-path probes.

`docker compose config --quiet` also passes. `./bin/bootstrap` rebuilt the
locked image and repeated the full passing checks, but its final stock app start
could not bind `127.0.0.1:8080` because an unrelated host listener already owns
`0.0.0.0:8080`. That listener was not stopped or changed. A temporary untracked
Compose override mapped the same app to `127.0.0.1:18080`; the container became
healthy and returned the exact `{"status":"ok"}` liveness body. The override
was then deleted. Releasing host port 8080 remains a manual host action if the
stock bootstrap endpoint is required.

The first GitHub matrix run (`31913795626`) exposed a clean-runner-only test
harness defect in the external-egress-disabled pass: its explicitly synthetic
`APP_SECRET` was only 14 bytes, while Phase 4 purpose-separated HMAC services
correctly fail closed below 16 bytes. The isolated runner now supplies a longer
test-only value; no production default or runtime fallback was added. Its trap
also disconnects MariaDB and Mailpit before removing the temporary internal
network. The repaired local isolated pass proves blocked external egress and
passed all 89 tests with 5,213 assertions, then proved the temporary network was
absent.

## Security and privacy evidence

- Synthetic reserved `example.test` identities and local Mailpit were the only
  mail/recipient inputs. No SMTP credential, live provider, production address,
  external account, DNS action, or production system was used.
- Tests prove raw session IDs are absent from session rows/list output; reset
  values are absent from the database/request target/runtime files; TOTP seeds
  and recovery values are absent from identity/secret rows and runtime files;
  serialized authenticated users omit password hashes.
- Failed login, lockout, reset, authorization, MFA, and re-authentication paths
  expose generic public errors. Audit and log processors reject or redact
  credential, token, cookie, session, address, control, and exception detail.
- Current-tree, reachable-history, generated-output, private-data, key, artifact,
  and public-path scans remain part of the aggregate gate.

## Manual actions and unresolved risks

- Operators must apply the forward-only migration and explicitly configure the
  actual trusted proxy hops. Administration must be served over reviewed HTTPS.
- Phase 4 recovery mail is intentionally Mailpit-only because Phase 3 forbids a
  live provider; production password-email delivery remains unavailable.
- Owner MFA can remain absent pending the later authenticated Doctor health
  policy. Phase 4 does not claim production sending readiness.
- Rate limiting mitigates but cannot guarantee availability during a distributed
  attack. Database unavailability also makes server sessions unavailable.
- Root/server-operator and process-memory compromise remain outside the stated
  protection boundary. The Phase 8 backup/recovery engine is still absent.
- No migration rollback, deployment, production configuration change, or manual
  database repair was performed.

## Phase-boundary evidence

No Phase 5 contacts, lists, imports, suppressions, templates, uploads, exports,
or related schema/routes/services were started. No campaign, queue, worker,
recipient action, tracking, backup, release, deployment, DNS, or production SMTP
behavior was started. Stop after Phase 4 at Anton's exact-SHA draft-PR approval
boundary; do not mark ready or merge without a new explicit instruction.
