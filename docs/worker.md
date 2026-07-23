# Delivery worker

**Status:** Design only; implementation is Phase 6.

The worker is a CLI-only Symfony Messenger consumer. No HTTP endpoint can start
delivery. Campaign state survives browser closure and process restart because
the MariaDB ledger, not the worker or browser, is authoritative.

## Preferred profile

A long-running worker under systemd or Supervisor:

- consumes only the asynchronous delivery transport;
- has explicit process user, environment, working directory, and restart policy;
- handles SIGTERM gracefully;
- observes bounded memory/time and configured concurrency;
- records a fixed-interval heartbeat;
- applies global and per-domain limits plus circuit breaker;
- routes exhausted failures to an observable dead-letter state;
- never exposes credentials in command line, environment diagnostics, or logs.

Phase 6 will provide reviewed unit files/configurations and tested commands.
None exists through Phase 1.

## Restricted cron profile

Where supervision is unavailable, cron may invoke a bounded worker that exits
before the next interval. A lock prevents overlap. This mode has lower
throughput and scheduled delivery may be delayed by up to the cron interval.
Doctor records the mode and gates new sends when heartbeat/scheduler health is
critical.

HTTP/URL cron is prohibited.

## Claim and delivery sequence

1. Claim an eligible identifier-only message.
2. Load its ledger record and conditionally enter processing state.
3. Verify campaign state and immediate global suppression.
4. Load/safely decrypt transport configuration at the adapter boundary.
5. Construct one recipient message with deterministic `Message-ID`.
6. Apply rate/circuit controls and recheck suppression immediately before send.
7. Classify result as accepted, transient, permanent, ambiguous, suppressed, or
   cancelled and commit durable state/audit evidence.
8. Retry only eligible transient outcomes within bounded backoff/jitter.

Queue payloads contain no raw address, rendered content, credential, token,
secret, or Doctrine entity.

## Operational semantics

- Pause stops new claims; an in-flight attempt may complete.
- Resume operates only on eligible pending/retry records.
- Cancel marks unclaimed work cancelled and cannot retract accepted mail.
- A transport timeout/disconnect after possible acceptance becomes ambiguous
  according to policy, not silently success or blind retry.
- Dashboard counts are derived from ledger states.

## Health and recovery

Doctor checks worker/scheduler heartbeat, queue age, stuck processing, dead
letters, disk, transport/TLS, rate settings, and circuit state. Worker restart,
concurrent claim, SIGTERM, database loss, provider failure, and ambiguous SMTP
outcomes require fake-service tests before Phase 6 completes.
