# ADR 0009: Supervised worker with bounded cron fallback

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

Reliable, timely delivery needs a long-running worker with graceful shutdown and
restart. Some self-hosted environments cannot run systemd or Supervisor, so a
constrained fallback is needed without exposing delivery through HTTP.

## Decision

Recommend a long-running Messenger worker under systemd or Supervisor. It
records a heartbeat, handles SIGTERM, has bounded memory/time, configured
concurrency/rates, a restart policy, circuit breaker, and dead-letter queue.

For restricted hosting, permit cron to start a non-overlapping worker that
processes a bounded amount of work and exits cleanly. The Doctor identifies the
mode, checks worker and scheduler heartbeats, states cron latency/throughput
limitations, and gates new sends when the chosen mode is unavailable.

No HTTP route may start or emulate either worker mode.

## Consequences

- Operators select and maintain one documented execution profile.
- Cron scheduling can delay campaigns by up to its interval and has lower
  throughput.
- State and locks must survive process restarts and overlapping invocations.
- Worker configuration becomes part of setup preflight and Doctor evidence.

## Security and privacy impact

CLI-only execution prevents anonymous or browser-triggered arbitrary work.
Process users require least-privilege database, private-file, key, and network
access. Command lines and supervisor logs must not contain credentials.

## Alternatives considered

- HTTP/URL cron was rejected because it creates a remotely callable delivery
  surface.
- Cron-only operation was rejected as the recommended profile because of
  latency, overlap, and throughput limitations.
- A permanently embedded worker in the web process was rejected because its
  lifecycle and privileges are unsafe and unreliable.

## Validation

Phase 6 tests long-running and bounded modes, non-overlap locks, heartbeat,
SIGTERM, timeout, memory/restart behavior, rate limits, stalled workers, and
Doctor sending gates.
