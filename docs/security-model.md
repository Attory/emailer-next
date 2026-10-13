# Security model

List CSV download uses the central `admin_list_download` route policy and a
second service-level Administrator check. POST requires a list-specific CSRF
token and recent `contact_data_access` confirmation. An active list is resolved
before the attachment response; only its three approved contact fields are
read through one unbuffered database query and statement snapshot. A mode-0600
temporary file is immediately unlinked, completed before headers are returned,
and closed after streaming. The response is private/no-store with a sanitized ASCII attachment
filename, `nosniff`, content length and disabled Nginx response buffering.
Spreadsheet-formula-leading cells use a reversible marked prefix that import
decodes before suppression checks. The audit contains only actor, opaque list
ID, format, scope and generated row count. The operation never
reads secret, session, consent, audit-payload or suppression-reason columns.

**Status:** Phase 13 complete Version-1 security controls under acceptance.

## Phase 13 closure controls

- Isolated restore evidence is issued only after actual restore, bounded full
  row/asset comparison, and quiescence. A purpose-separated master-key HMAC
  binds the receipt to the exact retained backup and original restore time.
  Interactive CLI-only recording re-verifies that artifact, rejects unsafe or
  future/mismatched evidence, records a redacted audit event, and never restores
  live data or advances a replay to the import time. Host isolation and approval
  remain operator responsibilities; root/key compromise can forge this evidence.

- Full CLI installation accepts secrets only through hidden interactive
  questions, exposes no secret-bearing option/argument, calls the same setup
  application services as HTTPS onboarding, and permanently locks setup after
  critical validation. It uses fake local Mailpit only and prints no MFA seed
  or recovery values.
- Master-key rotation requires an active Owner, consumes password plus MFA or a
  recovery code through the existing throttled re-authentication service, and
  authenticates the separately held current recovery package/passphrase against
  the live key before mutation.
  The operation requires delivery quiescence, creates a verified encrypted
  pre-rotation backup, takes the installation-wide maintenance lock and row
  locks, authenticates every existing envelope, re-encrypts every record with a
  fresh 32-byte key, atomically publishes a restrictive external key, and
  commits redacted audit evidence in the database transaction.
- A restrictive durable journal binds old/new key material to the opaque audit
  operation ID before publication. The maintenance lock prevents a concurrent
  reader from deciding an in-flight rotation. After a process crash or
  ambiguous commit, first key access reconciles the filesystem to committed
  audit evidence; no committed marker restores the old key and a committed
  marker retains the new key. Any ordinary failure follows the same path.
  Delivery remains stopped until a new recovery package and
  new verified backup are independently stored and Doctor passes. A reported
  compensation failure is an incident and requires the separately held old
  recovery key plus verified pre-rotation backup.
- Production dependencies must have a declared license in the approved
  allowlist and candidate inspection rejects `NOASSERTION`. The browser matrix,
  WCAG 2.2 AA target, automated checks, and mandatory human keyboard/assistive-
  technology acceptance are explicit.

## Phase 12 operational controls

- Login invokes cached lightweight read-only probes; full Doctor runs daily or
  on demand. Persistent findings include bounded redacted summary, risk,
  evidence, remediation, observation times, acknowledgement, and explicit
  sending-block classification. Acknowledgement never changes a gate.
- Sending evaluates only declared SMTP/configuration/delivery/suppression
  gates. Backup or restore-cadence findings remain prominent operational risks
  without unexpectedly blocking an otherwise safe send.
- Dashboard providers return counts, status codes, opaque operation names, and
  timestamps only. The composition layer rejects addresses and overlong text.
- Retention settings have database and application bounds, default off for
  scheduling, process at most 200 records per category, and exclude durable
  audit, suppression, campaign, content, and ambiguous-outcome evidence.
- External backup automation defaults off and supports only an approved
  filesystem mount outside release/private roots. Copies are exclusive,
  mode-0600, byte-counted, SHA-256 read-back verified, and represented only by
  redacted metadata. No cloud credential or network provider is introduced.

## Phase 9 supply-chain controls

- Candidate production requires a clean committed non-main source, an external
  owner-controlled Ed25519 key, a separately supplied pinned public key, and two
  byte-identical builds bound to the exact source commit and source epoch.
- The verifier uses regular single-link files, handle/path identity checks,
  bounded reads, strict semantic versions and archive naming, exact SPDX
  document identity, package license declarations, required runtime files, and
  signature/checksum/per-file digest verification before extraction.
- The private signing key is absent from Git, CI, candidate contents, client
  servers, logs and prompts. Rotation and compromise handling are defined in
  [release-signing.md](release-signing.md).
- CI uses a disposable synthetic signing key only and deletes it with every
  generated candidate. CI cannot publish, tag or deploy.

## Phase 8 operational controls

- Critical Doctor findings are stable redacted codes and block manual
  queue/schedule plus scheduler materialisation. Probe exceptions become a
  critical generic result; acknowledgement never disables a gate.
- Backups stream explicit module contributions through an authenticated sodium
  secretstream envelope. The master key is excluded; suppression and approved
  assets are restored, and delivery stays quiesced.
- Recovery packages use Argon2id and XChaCha20-Poly1305, hidden passphrases,
  restrictive files, and can recover only a missing master key.
- Packages fail closed on pinned Ed25519 signature, checksum, SPDX,
  manifest/file digests, unsafe paths/links, PHP/source compatibility, and disk
  reserve. Updates add locking, quiescence, verified backup, immutable install,
  forward migration, atomic pointer, Doctor, and rollback-or-restore.
- No browser endpoint accepts a backup path, recovery value, package, migration,
  restore, release switch, rollback, or deployment operation.

Security controls are default-deny and layered. A UI-hidden action is not a
security boundary; every protected invariant is checked at the route,
application-service, persistence, and operational boundary appropriate to it.

## Implemented controls through Phase 5

- Symfony Dotenv loading is disabled; runtime values are supplied externally,
  while `.env.example` contains placeholders only.
- Development Compose exposes the application and Mailpit UI on loopback and
  does not publish MariaDB.
- Test kernel construction accepts only credential-free Mailpit on port 1025
  via the Compose alias or loopback; CI also repeats tests on an internal
  network with an external-socket negative probe.
- Logs are structured JSON and use a central redaction processor. Exception
  messages are replaced, sensitive-key values and unsafe objects are removed,
  and address/credential/control-character patterns are sanitized.
- The only public route is fixed coarse liveness. It creates no session and
  discloses no version, database, migration, key, queue, worker, or transport
  state.
- Locked dependencies, maximum-level static checks, current/full-history
  secret/private-data scans, and an allowlisted artifact are blocking gates.

These platform controls alone do not imply sending readiness. Phases 3–4 add
secure setup, individual login/session administration, recovery, authorization,
recent re-authentication, and append-only redacted audit. Campaigns, workers,
public recipient routes, and production mail remain absent.

Phase 2 additionally implements a 160-bit Crockford Base32 ownership challenge,
SHA-256-only private state, expiry and attempt limits, source throttles, locking,
and one fixed configured proof path. Immutable deployments place that transient
path in service-owned setup storage outside root-owned release code. Proof reads
reject symlinks, non-regular or oversized
objects, unsafe permissions, and file replacement detected across the bounded
read. Successful proof deletes transient files and rotates the setup session.
Public failures are generic, and structured proof logs contain only the opaque
challenge identifier and result. Setup preflight accepts no secrets and stops
at an HTTPS gate.

The issuance and verification paths share one lock. An active unexpired
challenge cannot be overwritten by another visitor, while the authorized
session can make up to ten generic proof attempts without receiving the raw
code again. Backup-gated reset fails closed because Phase 2 cannot authenticate
a meaningful backup artifact; a restrictive file with a self-declared digest
is insufficient authority.

Phase 3 requires an explicit absolute production key path outside the complete
application/release/public tree. Creation/read validation walks every existing
ancestor, rejects links and unsafe ownership/modes, requires a mode-`0700`
immediate parent and mode-`0600` single-link file, and rechecks ancestor plus
path/open-file identity across bounded publication and reads. Test/development
paths are explicit and isolated. Secret records use
HKDF-SHA-256 purpose keys and fresh-nonce XChaCha20-Poly1305 envelopes bound to
installation UUID, record type, record UUID, and schema version. Unsupported
versions and every authentication mismatch share a generic fail-closed result.

The initial Owner login is Unicode-normalized and case-insensitive, uniquely
stored, and hashed with explicit Argon2id. A resumed creation accepts only the
same login and a password verified against the stored hash. Optional TOTP seeds
are encrypted; recovery codes are individually Argon2id-hashed, displayed once,
and atomically consumed once. Restart/skip deletes inactive enrollment material,
and confirmed or repeated enrollment cannot create a second applicable TOTP
set. Database/runtime plaintext secrets are never setup-session state.

Phase 3 mail testing is hard-limited to plaintext, unauthenticated local
Mailpit. The exact probed host, port, encryption mode, and absence of auth are
persisted; `tls_peer_verification = 0` records honestly that TLS was not used.
STARTTLS, implicit TLS, authentication, and every live destination are rejected
before transport construction. Production TLS/provider configuration remains
unavailable.

Every Phase 3 database/key/configuration/identity mutation and finalization
uses one cross-process lifecycle lock. A writer verifies immediately before its
operation that database completion and the filesystem installation lock are
both absent. Finalization validates and commits under that same lock, so no
setup record or secret can change after lock publication.

Phase 5 masks ordinary recipient views and requires Administrator authority,
recent re-authentication, CSRF, and audit for full reveal/export. Suppression is
durable, idempotent, and add-only in this phase. CSV imports use private random
files, bounded streaming validation, content hashing, MariaDB candidate staging,
safe row/reason reports, and a commit-time suppression recheck. Template HTML,
links, headers, and exact non-evaluating merge fields are validated before an
immutable version is stored; previews use synthetic data. Raster uploads are
privately quarantined, content-detected, dimension-bounded, decoded, and
re-encoded before random-name public placement.

## Fail-closed states

The application does not send when setup is incomplete/reopened, HTTPS is
absent, the public/private boundary is invalid, the master key is missing or
unsafe, migrations are pending, the suppression store is unavailable, mail
configuration cannot decrypt/authenticate, the selected worker/scheduler mode
is unhealthy, an artifact scan finds likely secret/private material, or
debug/test mode is active.

Setup mode exposes only the minimal setup flow and coarse liveness. A completed
installation has a private install lock; setup routes then return 404.

## Identity and authority

Every administrator has an individual account:

| Role | Authority ceiling |
| --- | --- |
| Owner | Secrets, recovery, users/roles, restore/upgrade approval, and all lower-role actions |
| Administrator | Configuration, users except protected Owner operations, content, contacts, campaigns, and reports |
| Operator | Contacts, templates, campaign workflow, test sends, and pause/resume/cancel; no security/secrets/backups/roles |
| Auditor | Read-only redacted reports, history, health, and audit |

Authorization is deny-by-default at both route and application-service level.
At least one active Owner always remains. Secret replacement, recovery,
privileged identity changes, restore/upgrade approval, and other sensitive
operations require recent re-authentication.

Emailer Next 1.0.5 has one installation-wide security level. **High is the
default and recommended level** for fresh and upgraded installations; it keeps
every existing purpose-bound administrative reauthentication gate. **Normal**
is an Owner-selected usability alternative that removes only those extra web
administration prompts. Initial login, configured login MFA, central route and
service authorization, CSRF, secure and revocable sessions, TLS and host/proxy
controls, throttling, audit, password/recovery protection, suppression and
secret redaction continue unchanged. Missing, unreadable, malformed or
unsupported level state is treated as High. A direct High-to-Normal request
requires Owner authority, CSRF, deliberate confirmation and recent High-mode
reauthentication; an atomic row-locked transaction records a redacted audit
event with actor, time, old/new levels and a source hash. The CLI master-key
rotation's explicit credential verification is outside the web administrative
policy and remains mandatory in both levels.

Passwords use framework-supported Argon2id settings plus a shared character-aware
length, local blocklist, and account-context policy. Login/recovery responses
are generic and atomically throttled by account and source. Re-authentication
has separate atomic account and source/session-context limits. TOTP is optional in version 1,
but production health cannot be green while an Owner lacks MFA unless an
operator-approved policy exception is recorded; the exception remains visible
as a warning. Recovery codes are random, hashed, displayed once, and consumed
once.

## Sessions and browser security

Sessions are server-side in MariaDB and have idle and absolute expiry. Session
IDs rotate on login, logout, privilege/password/MFA change, recovery,
re-authentication, and setup proof success as applicable. Users can view and
revoke their sessions.

The Phase 4 implementation stores session IDs and source/user-agent metadata as
purpose-separated HMACs, applies 30-minute idle and 12-hour absolute deadlines,
and retains monotonic revocation tombstones so a late response cannot recreate
logout, expired, reset, account/role, password, or MFA authority. Login failure
and recovery responses are generic. Account and source
throttles use bounded progressive delays and temporary locks whose locked
requests do not extend the denial window.

Accepted TOTP counters are stored under row lock and must increase strictly;
enrollment confirmation consumes its counter. Recovery requests always perform
the same bounded local Mailpit delivery class, while links are built only from
the stored canonical origin and retain the secret solely in the URL fragment.

Every implemented `admin_` route is covered by a central deny-by-default policy
registry and every privileged identity/audit application service checks its
actor again. Recent authentication is purpose-bound and expires after five
minutes. Audit summaries reject secret/address/control-bearing keys and values;
MariaDB triggers independently reject audit update and deletion.

Production cookies are `Secure`, `HttpOnly`, and `SameSite=Lax` or stricter
after compatibility tests, with narrow host/path scope. Trusted hosts/proxies
are explicit. Sensitive admin responses prohibit caching. A reviewed policy
sets HSTS after HTTPS validation, CSP without unsafe wildcards, `nosniff`,
frame-ancestor protection, Referrer-Policy, and Permissions-Policy.

Every browser mutation uses an unsafe HTTP method and CSRF. Public recipient
actions do not share the admin firewall/session and use their scoped token as
authority; they receive dedicated rate limits and cookie-free responses.

## Secret hierarchy

```text
external 32-byte master key
  -> HKDF context: encrypted-record/<record-type>/<key-version>
  -> XChaCha20-Poly1305 envelope
     AAD: installation UUID + record type + record ID + schema version
```

- The master key is generated with `random_bytes(32)`, stored outside `public/`
  with file mode `0600` and parent mode `0700`, and never stored in the database,
  repository, queue, log, session, browser, or ordinary backup.
- Each encrypted record uses a fresh nonce and a versioned envelope.
- Authentication failure is handled uniformly and fails closed.
- Passwords and recovery codes are one-way hashed, never encrypted.
- Rotation uses database transaction plus a restrictive crash-recovery journal;
  any failure or restart converges to the side selected by committed redacted
  audit evidence. Audit records contain lifecycle metadata but no secret value.
- The ordinary backup excludes the plaintext master key. A separate
  passphrase-encrypted recovery package is generated and verified explicitly.

The database stores no environment DSN password. Runtime database credentials
come from server configuration outside the repository and are never printed.

## Recipient tokens

Public tokens are opaque, purpose scoped, campaign/list and recipient scoped,
versioned, time bounded according to policy, and constant-time verified. They
contain no raw address. A token for tracking cannot unsubscribe; a token for one
campaign cannot authorize another. Key rotation supports a bounded overlap
window. Repeated unsubscribe is safe and idempotent.

Phase 7 uses `v<key-version>.<random-handle>.<HMAC>` bearer tokens. The HMAC
covers purpose, handle, campaign, delivery recipient, contact, expiry, and key
version. Its random 32-byte key is an encrypted dynamic secret; only the public
random handle is stored in clear. Verification uses constant-time MAC comparison
and fails generically for malformed, unknown-version, expired, revoked,
wrong-purpose, or tampered input.

Unsubscribe tokens expire after 365 days. A rotated key verifies for 365 days,
so a token issued immediately before rotation retains its advertised lifetime.
Expired token rows are purged after a 30-day operational grace in bounded
scheduler batches; durable suppression is independent. A forwarded link lets
its bearer suppress only the frozen recipient scope. Replays are intentionally
idempotent and disclose no address or account-existence detail.

`/r/` uses a security-disabled firewall before the administrator firewall, does
not start or bind an admin session, sets no cookie, and applies no-store policy.
Source and token limits use keyed cache identifiers rather than raw IPs or
tokens. Unsafe HTTP POST fails without redirect. Manual and RFC 8058 requests
share the HTTPS target; the latter accepts only the exact standardized form
pair and never redirects.

Every production message receives a manual `List-Unsubscribe` HTTPS URL and a
body link. `List-Unsubscribe-Post` is fail-closed by default and is snapshotted
only after an Owner records that the provider applies valid DKIM covering both
unsubscribe headers. Phase 8 Doctor verification of deployment/provider
readiness remains required before MVP sending is declared healthy.

## Data and output controls

- Private data is minimized and classified in
  [data-model.md](data-model.md).
- Raw recipient data is absent from public URLs, logs, exception text, metrics
  labels, queue names/payloads, filenames, and correlation IDs.
- Ordinary views mask addresses for roles below Owner. Active Owners see full
  contact/list and suppression addresses and nonsecret SMTP fields following
  the explicit Phase 13 usability authorization; each read is role checked,
  audited with opaque identifiers/counts, and served with private no-store
  headers. Administrator reveal and all exports retain recent purpose-scoped
  re-authentication in High, CSRF and audit. Credentials remain write-only.
- Production logs are structured JSON with UTC time and random correlation IDs.
  Central processors strip credentials, tokens, cookies, headers, addresses,
  bodies, uploaded rows, control characters, and unsafe exception detail.
- Audit events are append-only and store opaque targets and redacted change
  summaries.
- Public health is coarse. Detailed Doctor evidence is authenticated/CLI-only
  and safe to export.

## File, process, and network boundaries

Only `public/` is served. Private uploads, exports, setup state, logs, backups,
cache, dependencies, and key files are outside it. Server-chosen random names,
content-based MIME checks, bounded sizes, restrictive modes, and symlink
rejection protect file operations.

Delivery and restore are CLI-only. The web process cannot start a worker.
Workers receive only the permissions needed for their queue, data, key, and mail
operations. Source and immutable release files are not web-writable.
Production semantic-version directories are recursively root-owned and have no
write bits. The root-controlled updater executes only a target-version tree
whose package signature and file contract were verified before extraction or
service installation. It validates the root-only recorded predecessor against
the actual current pointer, authenticates the exact retained predecessor package,
and compares the complete installed predecessor tree to its signed file manifest
before quiescence or backup; ordinary application identities never receive a
release-write group. Legacy predecessor manifest rules are accepted only for
that same-version evidence input and can never authorize a target package.
The production lock DSN names a shared mode-`0700` directory under external
private storage. Bare temporary-directory flock storage is prohibited because
systemd `PrivateTmp` would otherwise isolate locks by service.
An update-created encrypted safety backup remains mode `0600`; after verifying
it, the root updater transfers that exact inode to the owner/group of the
mode-`0700` service backup directory so later service-owned verification and
restore remain possible.

Development and CI use fake SMTP and must be unable to resolve or connect to a
live SMTP target by default. No Doctor or setup operation modifies DNS,
third-party accounts, or credentials automatically.

## Supply-chain and release boundary

Composer dependencies are locked and audited. CI performs formatting, static
analysis, tests, migration checks, current-tree and full-history secret/private
data scans, and artifact inspection before checksum/SBOM generation. Release
artifacts are allowlisted and omit Git metadata, local environments,
development tools, tests, private/runtime data, logs, backups, and fixtures.

Starting with the MVP package tooling, a deterministic manifest binds semantic
version, source, platform/schema compatibility, migrations, and file digests.
A checksum, SPDX SBOM, and detached Ed25519 signature are verified against an
operator-pinned public key before any migration or release-pointer change.
Updates use new immutable directories, an exclusive maintenance lock, worker
quiescence, a verified encrypted backup, an atomic switch, and fail-closed
health checks. HTTP upload/update/restore and unattended update are prohibited.
Initial master-key creation, recovery, replacement, rotation staging, journal
publication, and rollback require a file `fsync` after flushing contents and
before publication. The externally stored master-key rotation journal and key
replacement also cross an `fsync` barrier on the containing directory after
every security-relevant link, rename, or unlink, so database audit evidence can
reconcile either side after process interruption or power loss.

A likely credential, private key, or private-data finding blocks merge and
release. Suppression of a scanner finding requires review showing it is a safe
placeholder or false positive; a blanket path exclusion is prohibited.

## Optional RSVP boundary

RSVP is off by default and is frozen only after two explicit opt-ins. Its
public form is cookie-free, rate limited, no-store, and authorized solely by a
dedicated opaque purpose-scoped bearer token. Input has a fixed bounded schema.
Responses are private, minimized, separately retained and backed up. Aggregate
views require Auditor authority; private export additionally requires
Administrator authority, CSRF, recent re-authentication in High, audit, and formula
neutralization.

## Security exceptions

An exception must name its owner, scope, expiry, threat-model impact,
compensating controls, test evidence, and removal plan. It cannot permit a real
secret/private record in Git, disable TLS peer verification, bypass suppression,
reopen setup, expose a private path, enable HTTP delivery, or claim exactly-once
SMTP semantics.

## Phase 13 Owner workflow repair

The explicitly authorized Owner visibility exception supersedes the Phase 5
masking requirement only for private Owner reads. Search addresses travel in
CSRF-protected POST bodies, never URLs, audit summaries or diagnostic exports.
Owner profile edits preserve archived status. Correcting a suppressed address
adds suppression for the corrected address in the same transaction while
retaining the previous suppression; duplicate-address failure rolls back both.

List copies share contacts and retain global suppression. Template edits append
immutable versions; copies require unique names. Send actions only preselect a
draft composer. Combined uploads validate through existing private CSV and HTML
services and roll back list/template/campaign writes on failure, removing staged
private files. Import commit checks authorization, selected-list identity and
draft state, then rechecks suppression. Preview creates no recipient ledger or
mail request. Existing campaign test, approval, immutable snapshot and queue
controls remain required.

SMTP Store requests preserve CSRF, Owner authorization, password plus enrolled
MFA, existing retry throttles, five-minute purpose grants, session rotation and
binding. A page-specific local JavaScript module activates the dialog and checks the
server grant expiry hint at Store click. The server skips empty confirmation
without charging a failed authentication attempt. The dialog holds unsaved fields in the current DOM only; without
JavaScript the same confirmation fields submit normally. Passwords/codes are
never saved as pending state or returned. No provider connection occurs on
configuration storage.

## 1.0.3 user-management confirmation repair

Create/update/delete user actions confirm the signed-in administrator inline
through the existing throttled password/MFA verifier. Only the `user_management`
purpose is accepted, within its five-minute lifetime. Successful confirmation
rotates/binds the browser session; CSRF is checked before credentials. Missing
or invalid confirmation produces an actionable 422 form without mutation. Only
creation email/role may be retained; initial/current passwords and codes are
never redisplayed or saved as pending actions. Role/last-Owner safeguards remain
server-side. The guide is authenticated and private/no-store.
