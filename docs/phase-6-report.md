# Phase 6 completion report

**Date:** 2026-08-22

**Phase/job:** Phase 6 — Campaign state machine, queue, worker, and mail delivery

**Branch:** `codex/phase-6-campaign-delivery`

**Base:** `origin/main` at
`c43d08eb5afe21a8062bdbbb4d4cd9005de98f41`

**Result:** The bounded Phase 6 implementation and local aggregate gates are
complete. The exact draft-pull-request head and terminal GitHub check results
are recorded in the final handoff and on the pull request.

## Starting state and scope

Origin was fetched with pruning. Local `main`, `origin/main`, and `HEAD` were
clean and synchronized at the Phase 5/MVP-plan squash merge before the Phase 6
branch was created. The master specification, phase jobs, clean-room checklist,
Phase 5 completion report, and relevant ADR, architecture, data, route, threat,
security, privacy, worker, testing, quality, development, installation, and
operations documents were read before implementation. No material conflict was
found.

Only authenticated campaign administration and durable delivery were
implemented. There is no public unsubscribe/token route, tracking, RSVP,
release/updater, backup engine, deployment, DNS change, or production-provider
contact.

## Implemented bounded scope

- Operators can create campaign drafts from an immutable published template and
  active lists, submit them for review, queue accepted test messages, schedule
  or queue approved campaigns, and pause, resume, or cancel unclaimed delivery.
  Administrators approve after purpose-bound recent reauthentication; an
  accepted test is required unless an explicit audited waiver is selected.
- Approval transactionally freezes content, sender identity, audience members,
  personalization profile data, recipient count, and content/audience digests.
  Templates containing Phase 7+ recipient URL fields are rejected.
- Queueing materializes a unique campaign/contact ledger before dispatch.
  Identifier-only Messenger messages carry a ledger/test ID and operation
  version; no address, content, profile, credential, or secret enters a queue
  payload. One message is constructed for one recipient with a deterministic,
  installation-bound `Message-ID`.
- Claims are serialized in MariaDB and check campaign state, operation version,
  global suppression, global/per-domain rate windows, configured concurrency,
  runtime quiescence, and circuit state. Suppression is checked again after the
  durable claim immediately before transport I/O.
- Delivery records a started attempt before external I/O. Accepted,
  suppressed, transient, permanent, and ambiguous outcomes are durable.
  Transient outcomes use bounded exponential delay plus deterministic jitter;
  exhausted, permanent, and ambiguous messages are dead-letter eligible.
  Ambiguous attempts are never blindly retried.
- Five consecutive transport-relevant failures open a five-minute circuit.
  Half-open state admits one probe. Recipient-specific permanent failures do not
  open a provider circuit. Runtime limits apply across campaigns and workers.
- A lock-protected scheduler queues due campaigns, reconciles pending retries,
  and marks interrupted attempts ambiguous while safely returning claims that
  never reached an attempt to retry. Pause blocks new claims, cancel marks only
  unclaimed work, and in-flight work may complete.
- Worker and scheduler heartbeats include delivery schema version `1`.
  `emailer:delivery:quiesce` and `emailer:delivery:resume` support a future
  release workflow without implementing the Phase 8 updater. systemd,
  Supervisor, graceful-stop, and bounded CLI-cron examples are documented.
- Owner-only SMTP configuration stores credentials through the encrypted secret
  vault after recent reauthentication. Production requires STARTTLS or direct
  TLS, certificate and peer-name verification, authentication credentials, and
  an SMTP AUTH capability before message content is transmitted. Development
  and test fail closed unless configuration is exact local unauthenticated
  Mailpit.

## Migration, dependencies, and routes

- No Composer dependency was added or updated.
- Forward-only migration added:
  `DoctrineMigrations\Version20260822000000`.
- The migration adds campaign metadata/list selection, immutable
  content/audience snapshots, queued test delivery, recipient ledger, delivery
  attempts, runtime/rate/circuit state, heartbeat state, and the explicit
  Doctrine Messenger table. It also broadens the Phase 3 Mailpit-only sender
  constraint to permit only local Mailpit or secure authenticated production
  SMTP forms.
- The migration idempotently repairs `contact_import.files_deleted_at` and its
  expiry index for installations that applied the earlier published form of the
  Phase 5 migration before those final fields were present.
- Authenticated administration routes were added for campaign list/detail and
  every lifecycle mutation, plus masked mail settings and Owner-only secure SMTP
  update. All routes are in the default-deny method/role registry; mutations are
  POST-only and CSRF protected. Approval and SMTP credential changes require
  purpose-bound recent reauthentication.

## Validation evidence

An isolated empty MariaDB 10.11 database applied all seven migrations through
`Version20260822000000` in 63 SQL queries, reported up to date on a second pass,
contained all expected delivery tables and the Phase 5 compatibility column,
and was then removed. The existing-upgrade test database also applied the new
migration and compatibility repair successfully.

The Phase 6 integration suite covers approval/test requirements, explicit
waiver, immutable snapshots, identifier-only payloads, deterministic and
idempotent delivery, exact Mailpit test/recipient sending, suppression after
queueing, bounded transient retry/jitter, permanent and ambiguous terminal
outcomes, dead-letter eligibility, circuit opening, concurrency deferral,
shared cross-campaign rate limits, pause/resume/cancel behavior, due scheduling,
interrupted-attempt recovery, worker/scheduler heartbeats, quiescence/schema
metadata, encrypted SMTP credentials, live-provider rejection, and a real
two-process ledger materialization race.

The complete Phase 0–6 suite passes 134 tests with 1,797 assertions. The
aggregate repository gate passes PHP-CS-Fixer, maximum-level PHPStan,
Deptrac and custom boundaries, shell validation, migrations/mapping, the full
test suite, Composer advisory/abandonment checks, secret/private-data scans,
production artifact build/inspection, platform requirements, and public-path
probes. GitHub CI results are recorded in the exact-head handoff.

## Security, privacy, operations, and residual risk

- Only reserved `example.test` identities and local Mailpit were used. No live
  SMTP provider or real credential/recipient data was contacted or stored.
- Queue and dead-letter messages are identifier-only. Provider references are
  hashed; audit summaries contain IDs/counts/classifications only. SMTP password
  input is encrypted at rest and never returned by the read model.
- SMTP transport acceptance is described as accepted, not delivered or inbox
  placement. Deterministic IDs reduce duplicate impact, but SMTP remains an
  at-least-once boundary: a disconnect after possible acceptance is deliberately
  ambiguous and requires operator investigation.
- Operators must supervise workers under a dedicated identity, invoke the
  scheduler each minute, monitor heartbeat/queue/dead-letter/circuit state,
  preserve graceful SIGTERM time, and quiesce before schema-changing
  maintenance. Cron is a bounded fallback, never an HTTP route.
- Production SMTP and deployment-specific values remain operator inputs outside
  Git. Root/server-operator, database-administrator, provider-account, and
  process-memory compromise remain outside the application protection boundary.

## Phase-boundary evidence

Phase 6 stops with authenticated fake-SMTP campaign delivery reliable and the
production transport safely configurable. Phase 7 public recipient safety is
not implemented: there are no unsubscribe headers/tokens/routes, public action
URLs, tracking pixels, click redirects, or RSVP behavior. Phase 8 release,
updater, backup, Doctor, and deployment behavior is also absent. Do not mark the
draft pull request ready or merge without Anton's explicit approval of the exact
repository, phase, pull request, and full current head SHA.
