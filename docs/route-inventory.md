# Route and command inventory

**Status:** Phase 13 Version-1 route review with bounded 1.0.5 security levels. The HTTP surface
includes the bounded Owner workflow repair below; CLI setup and master-key rotation now close their Version-1
contracts.

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
| `GET /r/{token}/unsubscribe` | Minimal unsubscribe confirmation/status | Purpose-scoped token | No admin session/cookie; no address or existence disclosure | 7 |
| `POST /r/{token}/unsubscribe` | Manual or RFC 8058 one-click unsubscribe | Purpose-scoped token | Exact bounded form; immediate idempotent global suppression; one-click never redirects or needs login/cookie/admin CSRF | 7 |
| `GET\|HEAD /r/{token}/view` | View the authorized message snapshot | Purpose-scoped token | Implemented; cookie-free, private/no-store frozen content | 10 |
| `GET\|HEAD /r/{token}/open.gif` | Optional approximate open event | Purpose-scoped token | Implemented; fixed response; disabled means no generated pixel | 10 |
| `GET\|HEAD /r/{token}/click/{link}` | Optional allowlisted click redirect | Purpose-scoped token | Implemented; HTTPS target fixed at publication; no open redirect | 10 |
| `GET|HEAD|POST /r/{token}/rsvp` | Optional bounded response form | Purpose-scoped opaque RSVP token | Cookie-free/private/no-store; source/token rate limits; fixed schema; frozen repeat policy | 11 |

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

### Implemented Phase 7 recipient route

`recipient_unsubscribe` accepts `GET` and `POST` at one HTTPS URL. GET validates
the bearer token and shows a generic confirmation without mutation. Manual POST
accepts only `confirm=unsubscribe`; RFC 8058 POST accepts only
`List-Unsubscribe=One-Click`. Both are idempotent, immediately add global
suppression, return without redirect, and require no cookie, login, admin
session, or admin CSRF. A dedicated firewall ignores any supplied admin cookie.
Per-source and per-token fixed windows use keyed identifiers. Every response is
no-store and omits the address, token, internal IDs, and existence detail.

## Authenticated administration surface

The prefix is `/admin`. Read routes require at least the role shown. Mutation
routes additionally require CSRF. Entries marked **recent auth** require a
fresh purpose-bound assertion in High. Normal removes that extra check through
the central policy; roles, CSRF and action-specific checks stay in force.

Phase 4 implements `/admin`, `/admin/logout`, `/admin/reauthenticate`,
`/admin/profile`, `/admin/profile/password`, `/admin/sessions`,
`/admin/sessions/revoke`, `/admin/mfa` and its start/confirm/disable/recovery
mutations, `/admin/users` and its create/update/delete mutations, and
`/admin/audit`, Auditor-readable `GET|HEAD /admin/analytics`, and
Administrator-only CSRF-protected `POST /admin/analytics/policy`.
`RoutePolicyRegistry` is exhaustive for every resolved
`admin_` route and rejects an absent policy or unapproved method.
Phase 5 implements the contact, list, import, suppression, export, and template
families shown below. Phase 8 implements Doctor, backup metadata/create,
retention execution, and release-status routes. Full-address reveal and export are Administrator-only,
POST/CSRF, recently re-authenticated in High, and audited. Import detail/report access
is restricted to its uploader or an Administrator. Campaign, delivery, Doctor,
other later rows below remain planned.

`POST /admin/lists/{id}/download` exports only that active list's membership as
`email,first_name,last_name`. It requires Administrator or Owner, a
`contact_data_access` confirmation within five minutes in High, and a list-specific
CSRF token. It streams a private/no-store CSV and records only the actor,
selected list ID, format, scope and generated row count in audit. Its control
is hidden from lower roles; the route returns 403 to them and no CSV for an
unknown or archived list.

`GET|HEAD /template-assets/{random filename}` preserves the Phase 5 public image
URL while storing the bytes outside immutable releases. The route accepts only
48-hex-character JPEG, PNG, or WebP names and returns no directory listing.

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
| `GET|HEAD /settings/security` | Administrator | Current installation security level; private/no-store | 1.0.5 |
| `POST /settings/security/update` | Owner | Central authorization and CSRF; High to Normal additionally requires purpose-bound recent High reauthentication and deliberate confirmation; redacted audit | 1.0.5 |
| `GET/POST /settings/secrets/*` | Owner | Replace/rotate only, never reveal; recent auth | 3/4 |
| `GET/POST/PATCH /settings/mail/*` | Administrator | Secret change Owner-only; test send bounded; recent auth | 3/4 |
| `GET/PATCH /settings/delivery` | Administrator | Safe bounded values; recent auth where sending impact is material | 3/4 |
| `GET/PATCH /settings/privacy` | Administrator | Tracking/RSVP defaults and retention; audited | 3/4 |
| `GET/POST/PATCH/DELETE /contacts/*` | Operator | Full-address display/export has separate permission | 5 |
| `GET/POST/PATCH/DELETE /lists/*` | Operator | Archive rather than destructive delete when referenced | 5 |
| `POST /lists/{id}/download` | Administrator | CSRF; recent contact-data reauthentication in High, bypassed in Normal; selected-list CSV only, private/no-store, redacted audit | 13 maintenance / 1.0.5 |
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
| `GET /analytics/*` | Auditor | Aggregate/redacted; full data separately authorized | 10 |
| `GET /admin/rsvp` | Auditor | Aggregate counts only; no private note or recipient identity | 11 |
| `POST /admin/rsvp/{campaign}/export` | Administrator | CSRF, High-mode recent reauthentication, audited opaque-identity CSV, formula neutralization | 11 / 1.0.5 |
| `POST /admin/campaigns/{id}/rsvp` | Operator | CSRF; draft-only explicit campaign enablement; audited | 11 |
| `GET /audit` | Auditor | Redacted append-only view; no edit/delete route | 4 |
| `POST /doctor/run` | Administrator | On-demand checks only; no automatic repair | 8 |
| `GET /doctor/export` | Auditor | Redacted report only | 8 |
| `POST /doctor/findings/*/acknowledge` | Administrator | Cannot turn off a sending gate; audited | 8 |
| `GET/POST /backups/*` | Owner | Create/verify metadata and bounded destination policy; recent auth; no public path | 8/12 |
| `POST /admin/backups/policy` | Owner | CSRF, recent auth, approved adapter type only; no path/credential | 12 |
| `GET/POST /retention` | Administrator | Bounded dry-run preview/apply with audit | 8/12 |
| `POST /admin/retention/policy` | Administrator | CSRF and enforced per-class bounds; scheduling defaults off | 12 |
| `GET /release` | Owner | Redacted version/compatibility status | 8 |

There is deliberately no HTTP route for a worker, arbitrary send, restore,
master-key export/rotation, setup reset, migration, release, deployment, or
rollback.

## CLI-only surface

| Command | Purpose and safety boundary | Phase |
| --- | --- | --- |
| `emailer:setup:install` | Credential-free preflight by default; `--complete` is the HTTPS-independent protected interactive install using the canonical setup services and never echoing secrets | 2/3/13 |
| `emailer:setup:reset` | Interactive reset; recent backup required; destructive flag when data exists | 2 |
| `emailer:secrets:replace` | Fail-closed replacement skeleton; no arguments or mutation until re-authenticated secret administration exists | 3 |
| `emailer:secrets:rotate-master-key` | Owner password/MFA plus current recovery-package authentication; quiescence and verified-backup gate; maintenance lock; transactional full re-encryption; atomic key replacement and failure compensation; redacted audit | 13 |
| `emailer:secrets:export-recovery-key` | Passphrase-encrypted recovery package; passphrase never stored | 8 |
| `emailer:secrets:recover-master-key` | Recover only a missing key from the separately stored package; never replace | 8 |
| `messenger:consume async` | Supervised or bounded/locked cron delivery worker | 6 |
| `emailer:scheduler:run` | Due schedules and maintenance under a non-overlap lock | 6/8 |
| `emailer:doctor:run` | Full redacted Doctor, with machine-safe exit status | 8 |
| `emailer:backup:create` / `verify` | Encrypted private backup and verification | 8 |
| `emailer:backup:restore` | Maintenance-only preflight, staging restore, and rollback | 8 |
| `emailer:backup:restore --drill-receipt` | Isolated real restore, full bounded snapshot comparison, authenticated private receipt | 13 repair |
| `emailer:backup:record-restore-drill` | Interactive authentication of exact retained-backup evidence; no live restore | 13 repair |
| `emailer:retention:run --dry-run` | Observable idempotent retention preview/apply | 8 |
| `emailer:operations:run` | Due full Doctor plus explicitly enabled bounded retention/external backup under one non-overlap lock | 12 |
| `emailer:release:inspect` / `install` / `update` / `check` | Pinned verification, immutable install, explicit maintenance update, and redacted status | 8 |

Exact Symfony route names, rate-limit values, token lifetimes, and fine-grained
permissions are finalized in their owning phases and generated into exhaustive
authorization/method/CSRF tests. Any additional public route requires a
threat-model update before implementation.

## Phase 13 full review result

The complete generated HTTP inventory was reconciled against this document and
the central default-deny administrative policy. The initial Phase 13 candidate
added no HTTP route; the authorized follow-up adds private routes below and no
public token purpose. Setup, identity/admin, content/import, campaign,
recipient-action, analytics/RSVP, Doctor/operations, backup/retention, and
release surfaces retain their authentication, minimum-role, unsafe-method,
CSRF/re-authentication, cache/cookie, and disclosure boundaries.

All listed CLI commands are implemented at their declared boundary. Generic
secret replacement remains intentionally unavailable because production SMTP
secret replacement is the authenticated Owner-only mail workflow. Master-key
rotation is now implemented as the separately re-authenticated, backup-gated,
quiesced transactional CLI operation. There is still no browser worker,
restore, recovery, master-key rotation, migration, package upload, release
switch, rollback, or deployment route.

## Phase 13 authorized Owner workflow routes

All entries require an active authenticated account, the existing administrative
request limiter, and private no-store responses. GET/HEAD do not mutate business
state; full Owner reads append only a redacted access audit. Every POST requires
a route-specific CSRF token. No route accepts secrets in its URL.

| Route/path | Methods | Minimum role | Data / mutation |
| --- | --- | --- | --- |
| `admin_list` `/admin/lists/{id}` | GET, HEAD | Operator | Paged members; Owner addresses full, others masked |
| `admin_list_browse` `/admin/lists/{id}/browse` | POST | Operator | Private bounded search; addresses allowed only for Owner |
| `admin_list_edit` `/admin/lists/{id}/edit` | GET, HEAD, POST | Operator | Rename active list |
| `admin_list_duplicate` `/admin/lists/{id}/duplicate` | GET, HEAD, POST | Operator | Required unique name; copy membership |
| `admin_contact_edit` `/admin/contacts/{id}/edit` | GET, HEAD | Owner | Full contact/profile editor; existing update POST preserves inactive/suppressed state |
| `admin_template_edit` `/admin/templates/{id}/edit` | GET, HEAD, POST | Operator | New immutable draft version and optional rename |
| `admin_template_duplicate` `/admin/templates/{id}/duplicate` | GET, HEAD, POST | Operator | Required unique name; new separate draft |
| `admin_campaign_import_commit` `/admin/campaigns/{id}/import/commit` | POST | Operator | Owned/authorized private CSV commit bound to selected list and draft |

Existing campaign create now accepts bounded private CSV/HTML multipart uploads
through the same validated services. Existing mail update performs inline
password/MFA confirmation when its purpose grant expires, rotates/binds the
session and retains Owner/CSRF checks. No provider is contacted by either form.

## 1.0.3 bounded repair

`GET/HEAD /admin/campaigns/guide` (`admin_campaign_guide`) is an authenticated,
private/no-store read available to Auditor and above. It serves the basic manual
and contains no personal records. Existing user create/update/delete POST routes
retain Administrator/Owner authorization and CSRF; expired user-management
confirmation now invokes inline current-password/MFA verification, with a
422 form on failure and no secret retention.

## Ripe 1.0.6 edition compatibility

The bounded Light 1.0.0 transition and native Ripe 1.0.5 update are documented
in [CLIENT-LIGHT-UPGRADE-1.0.6.md](CLIENT-LIGHT-UPGRADE-1.0.6.md) and
[RELEASE-1.0.6-IMPLEMENTATION.md](RELEASE-1.0.6-IMPLEMENTATION.md). The existing
`emailer:setup:install` CLI supports read-only inspection, execution, unfinished
recovery, acceptance and isolated old-Light-backup export. No HTTP update or
restore route is introduced. Contacts/lists remain independent of campaign-owned
Light recipients. Delivery remains held until explicit accepted readiness/resume.
