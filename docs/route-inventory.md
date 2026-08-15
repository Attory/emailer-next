# Route and command inventory

**Status:** Phase 4 implementation inventory. Coarse liveness, secure first-run,
identity/recovery, and authenticated administration-foundation routes are implemented.

This inventory is the security review baseline. Every implemented route must be
added here with method, authentication boundary, authorization rule, CSRF
policy, rate limit, cache policy, and data classification. GET and HEAD are
side-effect free. Every browser mutation uses POST, PUT, PATCH, or DELETE and
CSRF unless it is a purpose-scoped public recipient action.

## Public and setup HTTP surface

| Method and path | Purpose | Authority | Mutation / special policy | Phase |
| --- | --- | --- | --- | --- |
| `GET|HEAD /health/live` | Coarse process liveness only | Public | No detail, no session cookie, short fixed response | 1 |
| `GET /setup` | Show setup-required state and ownership instructions | Public only while unlocked | Rate limited; exact proof path is the only disclosed path | 2 |
| `POST /setup/proof/verify` | Read and verify fixed proof file | Setup session | CSRF, rate limit, generic failure, never accepts code in request | 2 |
| `GET /setup/preflight` | Safe system preflight | Verified setup session | HTTPS gate before any secret field | 2 |
| `GET/POST /setup/database` | Configure and test database | Verified setup session over HTTPS | CSRF; never echo DSN or password | 3 |
| `GET/POST /setup/organisation` | Configure singleton organisation | Verified setup session over HTTPS | CSRF | 3 |
| `GET/POST /setup/owner` | Create initial Owner and optional MFA | Verified setup session over HTTPS | CSRF; recovery values displayed once | 3 |
| `GET/POST /setup/mail` | Configure encrypted transport and fake/test delivery | Verified setup session over HTTPS | CSRF; secret never redisplayed | 3 |
| `GET/POST /setup/policy` | Delivery/privacy/retention defaults | Verified setup session over HTTPS | CSRF | 3 |
| `POST /setup/complete` | Run Doctor and write installation lock | Verified setup session over HTTPS | CSRF; critical failures block; single use | 3 |
| `GET /login` | Individual administrator login form | Anonymous only after lock | Generic response, no sensitive cache | 4 |
| `POST /login` | Authenticate account | Anonymous only after lock | CSRF and account/source throttles | 4 |
| `GET/POST /password-reset/*` | Request and consume reset token | Anonymous | Generic request result; random single-use token; throttled | 4 |
| `GET /r/{token}/unsubscribe` | Minimal unsubscribe confirmation/status | Purpose-scoped token | No admin session; no existence disclosure | 7 |
| `POST /r/{token}/unsubscribe` | Manual unsubscribe | Purpose-scoped token | Idempotent; form anti-abuse policy; no admin CSRF | 7 |
| `POST /r/{token}/unsubscribe/one-click` | RFC 8058 one-click unsubscribe | Purpose-scoped token | Idempotent; no redirect, login, cookie, or admin CSRF | 7 |
| `GET /r/{token}/view` | View the authorized message snapshot | Purpose-scoped token | No admin session; private-cache policy | 7 |
| `GET /r/{token}/open.gif` | Optional approximate open event | Purpose-scoped token | No cookie; disabled means route is inert/not generated | 7 |
| `GET /r/{token}/click/{link}` | Optional allowlisted click redirect | Purpose-scoped token | Target fixed at publication; no open redirect | 7 |
| `GET/POST /r/{token}/rsvp` | Optional bounded response form | Purpose-scoped token | Idempotency/repeat policy; no admin session | 7 |

After installation lock, every `/setup` path returns 404. Public recipient
responses never reveal a raw address, internal identifier, stack trace, secret,
or filesystem path.

### Implemented Phase 1 route

`health_live` accepts only `GET` and `HEAD` at `/health/live`. `GET` returns
HTTP 200 with the exact JSON body `{"status":"ok"}`, JSON content type,
`Cache-Control: no-store`, and no session cookie. `HEAD` returns HTTP 200 with
no body. It deliberately does not inspect or disclose database, migrations,
configuration, keys, Mailpit, worker, queue, or version state.

### Implemented Phase 2 setup routes

`setup_index`, `setup_proof_verify`, and `setup_preflight` implement the three
Phase 2 entries above. While unlocked, resolved ordinary application routes
fail closed with a generic HTTP 503 JSON response; unknown routes remain 404.
Challenge and proof requests use fixed-window per-client limits. Proof
verification requires CSRF and never accepts the proof code from HTTP input.
An active challenge makes later issuance requests return a generic HTTP 409
without replacing or redisplaying it. Retryable proof failures retain only the
original session's opaque challenge authority through the ten-attempt ceiling.
After the private installation lock exists, the subscriber returns an empty 404
for every setup route.

### Implemented Phase 3 setup routes

`setup_database`, `setup_organisation`, `setup_owner`, `setup_mail`,
`setup_policy`, and `setup_complete` implement the planned Phase 3 entries.
Every route requires the proof-authorized setup session, verified HTTPS,
no-store responses, a dedicated setup rate limit, and CSRF for mutation.
Secrets are accepted only in POST bodies, never retained in session, never
redisplayed, and mapped to generic failures. Mail testing is additionally
restricted to local Mailpit. Completion is POST-only, lock-serialized,
critical-preflight gated, and single-use.

## Authenticated administration surface

The prefix is `/admin`. Read routes require at least the role shown. Mutation
routes additionally require CSRF; entries marked **recent auth** require a
fresh re-authentication assertion.

Phase 4 implements `/admin`, `/admin/logout`, `/admin/reauthenticate`,
`/admin/profile`, `/admin/profile/password`, `/admin/sessions`,
`/admin/sessions/revoke`, `/admin/mfa` and its start/confirm/disable/recovery
mutations, `/admin/users` and its create/update/delete mutations, and
`/admin/audit`. `RoutePolicyRegistry` is exhaustive for every resolved
`admin_` route and rejects an absent policy or unapproved method. Settings,
content, campaign, delivery, Doctor, backup, release, and other later rows
below remain planned.

| Methods and family | Minimum role | Mutations and constraints | Phase |
| --- | --- | --- | --- |
| `GET /`, `GET /health` | Auditor | Redacted dashboard and Doctor results | 4/8 |
| `POST /logout` | Any authenticated role | Invalidates server session | 4 |
| `GET/PATCH /profile` | Any authenticated role | Own profile only; password/MFA changes rotate sessions | 4 |
| `GET/POST/DELETE /sessions` | Any authenticated role | Own session list/revocation; current-session rules | 4 |
| `GET/POST /reauthenticate` | Any authenticated role | Short-lived, purpose-bound assertion | 4 |
| `GET/POST/PATCH /users/*` | Administrator | Owner promotion/removal is Owner-only; last Owner protected; recent auth | 4 |
| `GET/POST/DELETE /mfa/*` | Any authenticated role for self | Owner policy; recovery regenerated once; recent auth | 4 |
| `GET/PATCH /settings/organisation` | Administrator | Singleton settings; recent auth for base URL | 4 |
| `GET/PATCH /settings/security` | Administrator | Owner-only policy exceptions; recent auth | 4 |
| `GET/POST /settings/secrets/*` | Owner | Replace/rotate only, never reveal; recent auth | 3/4 |
| `GET/POST/PATCH /settings/mail/*` | Administrator | Secret change Owner-only; test send bounded; recent auth | 3/4 |
| `GET/PATCH /settings/delivery` | Administrator | Safe bounded values; recent auth where sending impact is material | 3/4 |
| `GET/PATCH /settings/privacy` | Administrator | Tracking/RSVP defaults and retention; audited | 3/4 |
| `GET/POST/PATCH/DELETE /contacts/*` | Operator | Full-address display/export has separate permission | 5 |
| `GET/POST/PATCH/DELETE /lists/*` | Operator | Archive rather than destructive delete when referenced | 5 |
| `GET/POST /imports/*` | Operator | Private upload, preview, commit, rejection report; bounded and audited | 5 |
| `GET/POST /exports/*` | Administrator | Authorized private generation/download; formula neutralisation | 5 |
| `GET/POST /suppressions/*` | Operator | Global and idempotent; removal policy requires elevated review | 5 |
| `GET/POST/PATCH/DELETE /templates/*` | Operator | Publish/archive transitions; permanent delete Owner-only | 5 |
| `GET/POST /templates/*/preview` | Operator | Synthetic data only | 5 |
| `POST /templates/*/test` | Operator | Approved test destination and fake-only outside production | 5/6 |
| `GET/POST/PATCH /campaigns/*` | Operator | Draft construction; selected inputs are explicit | 6 |
| `POST /campaigns/*/review` | Operator | Valid state transition only | 6 |
| `POST /campaigns/*/approve` | Administrator | Test-send requirement or audited waiver | 6 |
| `POST /campaigns/*/schedule` | Operator | Typed confirmation; approved campaign only | 6 |
| `POST /campaigns/*/queue` | Operator | Typed confirmation; production sending gates; idempotent | 6 |
| `POST /campaigns/*/pause` | Operator | Idempotent; in-flight attempt may finish | 6 |
| `POST /campaigns/*/resume` | Operator | Idempotent; eligible ledger rows only | 6 |
| `POST /campaigns/*/cancel` | Operator | Idempotent; cannot retract accepted mail | 6 |
| `GET /campaigns/*/progress` | Auditor | Counts from durable ledger, masked detail | 6 |
| `GET /analytics/*` | Auditor | Aggregate/redacted; full data separately authorized | 7 |
| `GET /rsvp/*`, `POST /rsvp/*/export` | Auditor / Administrator | Private values; export is authorized and neutralized | 7 |
| `GET /audit` | Auditor | Redacted append-only view; no edit/delete route | 4 |
| `POST /doctor/run` | Administrator | On-demand checks only; no automatic repair | 8 |
| `GET /doctor/export` | Auditor | Redacted report only | 8 |
| `POST /doctor/findings/*/acknowledge` | Administrator | Cannot turn off a sending gate; audited | 8 |
| `GET/POST /backups/*` | Owner | Create/verify metadata only; recent auth; no public path | 8 |
| `GET/PATCH /retention` | Administrator | Dry-run preview and audited policy change | 8 |
| `GET /release` | Owner | Redacted version/compatibility status | 8 |

There is deliberately no HTTP route for a worker, arbitrary send, restore,
master-key export/rotation, setup reset, migration, release, deployment, or
rollback.

## CLI-only surface

| Command | Purpose and safety boundary | Phase |
| --- | --- | --- |
| `emailer:setup:install` | HTTPS-independent interactive install; never echoes secrets | 2/3 |
| `emailer:setup:reset` | Interactive reset; recent backup required; destructive flag when data exists | 2 |
| `emailer:secrets:replace` | Fail-closed replacement skeleton; no arguments or mutation until re-authenticated secret administration exists | 3 |
| `emailer:secrets:rotate-master-key` | Fail-closed rotation skeleton; transactional re-encryption remains unavailable pending later recovery/authentication controls | 3 |
| `emailer:secrets:export-recovery-key` | Passphrase-encrypted recovery package; passphrase never stored | 8 |
| `messenger:consume async` | Supervised or bounded/locked cron delivery worker | 6 |
| `emailer:scheduler:run` | Due schedules and maintenance under a non-overlap lock | 6/8 |
| `emailer:doctor:run` | Full redacted Doctor, with machine-safe exit status | 8 |
| `emailer:backup:create` / `verify` | Encrypted private backup and verification | 8 |
| `emailer:backup:restore` | Maintenance-only preflight, staging restore, and rollback | 8 |
| `emailer:retention:run --dry-run` | Observable idempotent retention preview/apply | 8 |
| `emailer:release:check` | Version, migration, artifact, and support checks | 8 |

Exact Symfony route names, rate-limit values, token lifetimes, and fine-grained
permissions are finalized in their owning phases and then generated into an
authorization matrix test. Any additional public route requires a threat-model
update before implementation.

The two Phase 2 CLI commands remain implemented. `emailer:setup:install`
continues to provide credential-free CLI ownership/preflight; the secure
credential workflow is the HTTPS wizard in this phase. The two Phase 3 secret
commands exist only as fail-closed, argument-free skeletons and cannot replace
or rotate anything. No worker, restore, backup, release, or later CLI command is
implemented.
