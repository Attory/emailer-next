# ADR 0006: Symfony Messenger with Doctrine transport

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

Campaign delivery must survive browser closure, process restart, and temporary
transport failure. The initial deployment should avoid requiring a separate
queue service while retaining supervised workers, retry handling, and durable
claims.

## Decision

Use Symfony Messenger with Doctrine transport for the initial asynchronous
queue. Create a durable campaign-recipient ledger row before dispatch. Queue
messages carry opaque identifiers and operation/version metadata only—never
credentials, raw recipient addresses, rendered bodies, tokens, or Doctrine
entities.

The delivery ledger, not the Messenger row or browser, is the authoritative
business state. A failed/dead-letter transport is configured and observable.

## Consequences

- MariaDB load includes queue operations and must be monitored/indexed.
- Queue schema and cleanup are migration/retention concerns.
- A future external queue can replace the adapter without changing delivery
  semantics, but requires an ADR and recovery testing.
- Message handlers must be idempotent and transaction-aware.

## Security and privacy impact

Keeping payloads identifier-only reduces leakage in queue tables, failures, and
diagnostics. Database access still exposes sensitive business state and needs
least privilege. An HTTP route cannot invoke `messenger:consume`.

## Alternatives considered

- In-request sending was rejected for authorization, timeout, recovery, and
  progress-integrity reasons.
- File queues were rejected for concurrency and durability limitations.
- Redis/RabbitMQ were deferred to reduce version 1 operational dependencies.

## Validation

Integration tests cover durable dispatch, unique ledger creation, multiple
workers, crash/restart, retry/dead-letter, malformed/replayed messages, and
payload scans for secrets/PII.
