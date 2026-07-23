# ADR 0007: At-least-once delivery semantics

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

SMTP can accept a message and lose the connection before the client receives a
definitive response. No local transaction can atomically commit both the
application database and an external mail provider. Claiming exactly-once
delivery would be incorrect.

## Decision

Provide at-least-once processing with best-effort duplicate minimisation:

- unique campaign/contact ledger constraint;
- deterministic `Message-ID`;
- ledger check before every attempt;
- provider idempotency key where supported;
- transactional local state transitions;
- bounded classified retries;
- explicit ambiguous state and audit event after uncertain outcomes.

“Accepted” means accepted by the configured transport, not delivered to an
inbox. Ambiguous outcomes are not automatically called success or retried
without the configured safe policy.

## Consequences

- A small residual duplicate risk is disclosed and visible.
- Progress has more states than sent/failed.
- Operators need guidance for ambiguous records.
- Handlers, pause/resume/cancel, and scheduling must be idempotent and
  race-tested.

## Security and privacy impact

Honest state avoids silent repeated contact and misleading reports. Suppression
is rechecked immediately before every transport attempt, including retries.
Deterministic identifiers contain no recipient PII.

## Alternatives considered

- Exactly-once delivery was rejected as impossible to guarantee over SMTP.
- At-most-once was rejected because a worker crash could silently lose
  recoverable messages.
- Blind retry after every timeout was rejected because it increases duplicate
  risk.

## Validation

Fake-SMTP tests simulate transient, permanent, timeout, disconnect-after-accept,
worker crash, replay, and concurrent claims. UI/text snapshots preserve
accepted-versus-delivered and ambiguous wording.
