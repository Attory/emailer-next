# Architecture

## Release 1.0.4 selected-list download

The existing server-rendered Lists action posts to an Administrator-only
route. Identity's central route registry, the Contacts application service,
recent contact-data confirmation, and list-specific CSRF all gate a full
address download. Contacts resolves one active list and records a redacted
audit request before generation. A single unbuffered sorted MariaDB query has
one statement snapshot, keeping application memory bounded while emitting only
the three importable CSV columns into a restrictive, immediately unlinked temporary file. The
response streams that completed file and has a private/no-store cache
policy and sanitized attachment filename. The export neither changes nor
reports suppression state; campaign approval and delivery continue to check
the global suppression store independently. No schema, worker, dependency,
public route, or delivery behavior changes.

**Status:** Phase 13 Version-1 hardening architecture under acceptance.

**Last reviewed:** 2026-08-27

## System context

The Phase 13 walkthrough repair keeps the schema and HTTP surface unchanged.
`Backup` now issues private authenticated evidence after an isolated real
restore and complete bounded snapshot comparison. A separate interactive CLI
records that evidence against the originating retained backup and Audit
contract. Health remains read-only and derives cadence from the recorded actual
restore time; there is no acknowledgement, manual timestamp, or SMTP bypass.

Phase 13 preserves the modular-monolith and schema boundaries. It closes two
previously reserved CLI contracts without adding an HTTP route: the protected
terminal installer orchestrates existing Setup, Configuration, Identity, and
Health services, while `Secrets` owns application-layer master-key rotation
using Backup and Audit contracts plus the shared installation-wide maintenance
lock. A restrictive external journal bridges the filesystem publication and
database commit: first key access after a crash selects old or new key from the
committed opaque audit marker, while the maintenance lock blocks premature
reconciliation by another process. The current schema remains `20260828000000`.

Phase 12 adds an `Operations` application-coordination module. It owns only
bounded scheduler policy/run evidence and consumes published Health,
Housekeeping, Backup, and redacted status-provider contracts. Data-owning
modules keep their tables and expose fixed aggregate status projections; the
dashboard never performs cross-module recipient/content queries.

Phase 11 adds optional RSVP. Installation policy, frozen per-campaign enablement,
private responses, aggregate reports, export, retention, and backup ownership
live in `Analytics`; public token verification and the bounded cookie-free form
live in `RecipientActions`. Delivery consumes a dedicated RSVP-link contract.
The feature is off unless both installation and draft campaign opt in.

Emailer Next is one self-hosted installation for one organisation on one
server. Several individually authenticated administrators may use it, but there
is no tenant selector, tenant identifier, shared control plane, or
cross-organisation data path.

The application is a Symfony 7.4 LTS modular monolith running on PHP 8.4 or a
newer supported branch. MariaDB is the transactional source of truth. Twig,
AssetMapper, and small Stimulus controllers provide the server-rendered
administrative UI. A supervised CLI worker performs delivery through Symfony
Messenger; an HTTP request can enqueue approved durable work but can never run
the delivery worker.

## Implemented through Phase 7

The repository implements the platform shell and secure setup boundary:

- a final Symfony kernel with externally supplied runtime configuration;
- Doctrine DBAL/ORM and Migrations configured for MariaDB, including the
  bounded Phase 3 setup/configuration/Owner/secret schema;
- Mailer, Doctrine Messenger transports, Security, Twig, AssetMapper/Stimulus,
  Validator, RateLimiter, Lock, and Monolog framework wiring;
- central structured JSON log processing with conservative redaction;
- the `Health` module's coarse liveness controller;
- Docker Compose application, MariaDB, and Mailpit services;
- CI, static architecture enforcement, isolated mail tests, and an allowlisted
  production-shaped artifact.

Phase 2 added the `Setup` module: fail-closed setup-mode routing, a 160-bit
filesystem ownership challenge, private hashed setup state, safe fixed-path
proof verification, post-proof HTTPS gating, system preflight, installation
lock/reset abstractions, and the bounded CLI alternative. Identity, credential
collection, content, campaign, and delivery remained unimplemented.

Phase 3 completes the setup-only path with MariaDB schema migration, an
external restrictive master-key file, HKDF/XChaCha20-Poly1305 secret records,
singleton organisation and sender configuration, an Argon2id initial Owner,
optional encrypted TOTP plus hashed recovery codes, bounded delivery/privacy
defaults, exact plaintext/unauthenticated Mailpit-only transport evidence,
critical setup preflight, and compensating installation-lock finalization. All
Phase 3 writers and finalization share one cross-process lifecycle lock. The
fixed installation-completion event remains separate from general audit.

Phase 4 adds individual administrative accounts, the four-role hierarchy,
deny-by-default route and service authorization, password/TOTP authentication,
purpose-bound re-authentication, database sessions, password recovery through
Mailpit, a server-rendered administration shell, and append-only redacted audit
events. Session identifiers and source metadata are stored only as
purpose-separated HMACs. Sessions have 30-minute idle and 12-hour absolute
limits and rotate when authentication authority changes. A central registry
defines every `admin_` route's methods and minimum role; an unregistered route
fails closed. Identity services repeat authorization and serialize final-Owner
mutations with row locks.

Phase 5 adds contact profiles and bounded custom fields, lists and idempotent
memberships, durable global suppression, private two-pass CSV imports, guarded
full-address export, immutable sanitized template versions, synthetic preview,
and decoded/re-encoded raster assets. Import candidates are staged in MariaDB
so preview and commit remain bounded-memory; commit rechecks suppression inside
its transaction. Full-address reveal/export requires Administrator authority,
recent re-authentication, CSRF, and audit. Template syntax is non-evaluating and
allowlisted. At the Phase 5 boundary, campaigns, delivery, and public recipient
routes remained unimplemented.

Phase 6 adds immutable campaign approval snapshots, durable identifier-only
Messenger jobs, a unique recipient ledger, supervised delivery, suppression
checks immediately before transport, bounded retries/rate/concurrency/circuit
state, honest progress, and scheduler/worker recovery. Phase 7 adds a dedicated
cookie-free public firewall, authenticated versioned unsubscribe bearer tokens,
manual confirmation and RFC 8058 POST handling, immediate idempotent global
suppression, and mandatory per-message unsubscribe URLs. Token key material is
random, encrypted through the secret vault, and separated by key version.
One-click headers are snapshotted and emitted only after an Owner attests that
the provider will DKIM-sign both unsubscribe headers; manual unsubscribe remains
available without that attestation.

Phase 8 adds module-owned readiness probes aggregated by Health, persistent
redacted Doctor evidence, and fail-closed sending gates at manual queue,
schedule, and scheduled materialisation. Versioned contributors feed streaming
authenticated-encrypted backups of database state and approved assets. A
separate Argon2id/XChaCha20-Poly1305 package can recover only a missing master
key. CLI restore creates a pre-restore backup and leaves delivery quiesced.
Offline tooling builds deterministic archives with a manifest, checksum, SPDX
SBOM, and detached Ed25519 signature; the CLI pins an external public key before
extracting into immutable release directories. Updates quiesce, back up,
migrate, atomically switch, run Doctor, and use compatible pointer rollback or
verified restore. Phase 9 turns that mechanism into the reproducible signed
`0.1.0` candidate workflow, with exact source identity, SPDX licenses,
operator-controlled signing custody, clean-install/update rehearsal, and no
publication or deployment side effect.

Phase 13's combined release-train update never delegates compatibility policy
to an older predecessor binary. The operator authenticates the `1.0.0` package
before extraction or root configuration, freezes a root-owned target-code
tree, records the exact source version in a root-only environment file, and
runs that target updater through a root-controlled one-shot unit. The updater
requires the recorded source to equal the basename of the current immutable
pointer, verifies the retained signed predecessor package under the pinned key,
and requires every installed predecessor file to match that signed manifest
before mutation. It then publishes a recursively root-owned, non-writable release.
All processes use the explicit shared flock path under application-private
storage; systemd private temporary directories never partition the maintenance
lock.

Template assets move to persistent private storage outside immutable release
directories and are exposed only through the exact random-name public asset
route; updates therefore never make mutable data part of a release tree.

### Setup state transition

An absent private installation lock keeps ordinary application routes at a
generic setup-required response. `GET /setup` issues a rate-limited challenge;
`POST /setup/proof/verify` verifies only the fixed configured
`.emailer-install-proof`, deletes proof and private state on success, and
rotates the session. Development defaults that path to the application root;
immutable client releases set `EMAILER_INSTALL_PROOF_FILE` to a service-owned
setup directory outside release code so the service can delete the transient
proof without release-write authority. Preflight then requires
the verified session and HTTPS. A present lock makes every setup route return
404. Phase 3 publishes the lock only after all setup rows, explicit external-key
validation, exact Mailpit evidence, safe defaults, and CLI capability checks
pass. Every writer rechecks both database completion and filesystem lock while
holding the same lifecycle lock used by finalization.
Database completion precedes lock publication; publication failure is
compensated, while an interruption before publication remains resumable and
never appears installed.

```mermaid
flowchart LR
    Admin[Administrator browser] -->|HTTPS| Web[Web server]
    Recipient[Recipient mail client] -->|HTTPS opaque token| Web
    Web -->|only public/| App[Symfony application]
    App --> DB[(MariaDB)]
    App --> Private[Private storage]
    App --> Queue[(Doctrine Messenger tables)]
    Worker[Supervised CLI worker] --> Queue
    Worker --> DB
    Worker --> Key[External master-key file]
    Worker --> Mail[Configured mail transport]
    Scheduler[CLI scheduler] --> App
    App --> Logs[Redacted structured logs]
    Worker --> Logs
```

## Architectural invariants

1. Only `public/` is a web document root. Source, Git metadata, configuration,
   private files, logs, backups, dependencies, and the master key are never
   web-served.
2. Setup is fail-closed. Until an installation lock exists, ordinary
   administration and all delivery are unavailable.
3. MariaDB owns durable business state. Browser sessions and in-process memory
   never define campaign progress.
4. Dynamic secrets are authenticated-encrypted in the database. The 32-byte
   master key is stored in a restrictive external file and never in the
   database, queue, logs, session, or Git. Rotation journals both key sides only
   in the same external mode-`0700` custody boundary until committed audit
   evidence resolves the crash window.
5. Delivery is at-least-once processing with best-effort duplicate
   minimisation. The product never claims exactly-once SMTP delivery.
6. Every production message is constructed for one recipient. There is no
   bulk-BCC path.
7. Every send path checks the global suppression store immediately before the
   transport attempt.
8. Browser mutations require authentication where applicable, authorization,
   an unsafe HTTP method, and CSRF protection. Public recipient mutations use
   a purpose-scoped opaque token instead of an admin session.
9. Tests and default development configuration can use fake SMTP only.
10. Domain and application rules do not live in controllers, templates,
    Doctrine entities, console commands, or JavaScript.

## Internal structure

Each required module is placed below `src/Module/<ModuleName>/` and is split
into four inward-facing layers:

```text
Presentation -> Application -> Domain
Infrastructure -> Application/Domain contracts
```

- **Domain** contains business rules and value objects without framework or
  persistence dependencies.
- **Application** coordinates use cases, transactions, authorization checks,
  and ports.
- **Infrastructure** implements persistence, mail, filesystem, crypto, clock,
  and framework adapters.
- **Presentation** maps HTTP/CLI input to application commands and maps results
  to safe responses.

Cross-module calls use explicit application contracts, immutable identifiers,
or redacted domain events. Modules do not query another module's tables, reuse
its Doctrine entities, or bypass its authorization and invariants. Detailed
ownership and allowed dependencies are in
[module-boundaries.md](module-boundaries.md).

## Runtime flows

### Administrative request

The web server terminates or forwards verified TLS, serves only `public/`, and
passes the request to Symfony. Security resolves the individual account and
session. Route authorization and, for mutations, CSRF and recent
re-authentication are checked before an application service starts a database
transaction. Responses use security headers, suppress sensitive caching, and
show a correlation identifier rather than internal detail on failure.

From 1.0.5, the singleton organisation row holds a constrained `high` or
`normal` security level. A database-backed policy reads it at each
recent-authentication decision, with missing, unreadable or invalid state
falling back to High. The reauthentication service is the sole web decision
point. The Owner-only transition locks the row, enforces a High-mode check
before lowering, writes the level and redacted audit event in one transaction,
and has no cached mode to become stale across requests or processes.

### Campaign queueing

Approval snapshots the chosen template and audience into durable records.
Queueing creates unique campaign-recipient ledger rows transactionally before
dispatching identifiers-only messages to the Doctrine transport. Serialized
messages contain no address, rendered body, credential, token, or secret.

### Delivery

A CLI-only worker claims a ledger item, checks campaign state and suppression,
loads the encrypted transport configuration, decrypts it only at the adapter
boundary, builds one message, applies global and per-domain limits, and attempts
delivery. It records accepted, retryable, permanent, suppressed, cancelled, or
ambiguous state. A crash is recovered from durable state; an ambiguous SMTP
outcome is exposed to an operator rather than guessed.

### Public recipient action

A public route accepts an opaque, purpose-scoped token. Verification checks
authenticity, purpose, scope, key version, and expiry without an admin session
or raw recipient data in the URL. Mutations are idempotent. Public endpoints do
not start an admin session and emit only redacted telemetry. A forwarded
unsubscribe link is bearer authority for only its frozen recipient/campaign
scope and never displays that address.

## Storage boundaries

- **MariaDB:** durable application, session, queue, audit, health, and ledger
  state.
- **Private directory:** temporary imports, rejection reports, exports,
  backups, setup state, and other non-public files. It is outside `public/`.
- **Public asset directory:** only reviewed static application assets and
  sanitized, non-executable uploaded raster images.
- **Secret-key file:** explicitly configured absolute external 32-byte master
  key, mode `0600`, parent mode `0700`; production rejects application/release/
  public placement, unsafe ancestor ownership/modes, links, and ancestor/path
  replacement.
- **Logs:** structured UTC records with strict redaction and bounded retention.

The initial relational design is in [data-model.md](data-model.md). Paths in
documentation are examples only and never authorize placing a real secret in
the repository.

## Deployment profiles

The preferred profile is PHP-FPM behind Nginx or Apache, MariaDB, a
long-running Messenger worker under systemd or Supervisor, and a CLI scheduler.
A restricted-hosting profile may run a bounded, locked worker from cron. The
Doctor must identify the selected profile, verify heartbeats, and block new
production sends when its required execution mechanism is unavailable.

Releases are immutable artifacts built from committed source and the lockfile.
Each semantic version occupies a new release directory; an atomically switched
current-release pointer selects the active code. Client configuration, the
external master key, private/writable state, and backups remain outside every
release. A deterministic manifest, checksum, SPDX SBOM, and detached Ed25519
signature bind package identity and compatibility. Only an explicit operator
CLI may verify, quiesce, back up, migrate, switch, and health-check an update.
Production publication uses authenticated target-version code under a
root-controlled one-shot service; ordinary web/worker identities cannot change
release ownership or modes. There is no web or unattended update path. Phase 1 tests the artifact boundary,
Phase 8 implements this topology, and Phase 9 rehearses the first MVP package.
Deployment, publishing, and rollback remain explicit operator actions.

## Decision records

The design is governed by accepted ADRs `0001` through `0013` in
[docs/adr](adr/). A change to an invariant requires a superseding ADR, updated
threat model, route/data review where relevant, and passing quality gates.

## Phase 13 Owner workflow repair

Presentation adds private list/template editors and a multipart campaign composer.
Campaign preparation coordinates existing Contacts/Import and Templates through
Application contracts, sharing the existing DBAL transaction for atomic business
state and cleaning staged upload files on failure. Draft creation never calls
delivery. Identity exposes a purpose-scoped sensitive-action contract for inline
SMTP confirmation and session rotation; Configuration retains encrypted storage.
Contacts requests suppression preservation through an add-only Suppression
contract inside profile updates. No schema, worker, dependency or public surface
is added. Existing immutable snapshots and pre-attempt suppression remain intact.

## Release 1.0.2 newsletter workspace

Twig and AssetMapper retain ownership of rendering. Two small page-local native
modules guide the composer and poll an authenticated, no-store campaign counts
endpoint; no browser business state, external runtime or new frontend package
is introduced. No-JavaScript forms and explicit test/approval/send boundaries
remain usable. Configuration owns Owner delivery-policy updates through a
purpose-scoped confirmation contract. Delivery locks the shared runtime and
fresh policy alongside its campaign claim and durable campaign rate window.
Draft content changes increment the campaign revision while retaining historical
test results and immutable approved snapshots. Superseded pending tests terminate
without provider I/O. An in-flight test can record its historical result; approval
requires both the displayed review revision and an accepted test revision to match.
