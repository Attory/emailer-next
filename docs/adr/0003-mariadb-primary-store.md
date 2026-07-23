# ADR 0003: MariaDB as the primary transactional store

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

Identity, suppression, audience snapshots, delivery state, queue claims,
sessions, audit events, and recovery require durable transactions and
constraints. File-backed JSON/CSV state cannot safely coordinate concurrent web,
worker, and scheduler processes.

MariaDB 10.11 is the approved minimum initial production target. Its official
maintenance table reviewed on 2026-07-23 lists maintenance through February
2028. Newer branches require compatibility testing rather than assumption.

## Decision

Use MariaDB 10.11 or a project-verified newer supported release through Doctrine
DBAL/ORM and Doctrine Migrations. MariaDB is the source of truth for durable
business state, server-side sessions, and the initial Doctrine Messenger
transport.

Use database constraints for unique/integrity invariants and transactional
state transitions where supported. Store UTC timestamps. Do not use SQLite as a
production or concurrency-equivalent test substitute.

## Consequences

- Production and integration testing require MariaDB.
- Migrations are versioned, reviewed, and checked before sending.
- Backup/restore must cover database state consistently with private assets.
- Database availability is a sending gate.

## Security and privacy impact

A stolen database contains private data and encrypted secrets, but not the
external master key. Least-privilege database credentials and network controls
are still required. Constraints reduce race-driven suppression and duplicate
ledger failures.

## Alternatives considered

- Files, CSV, JSON, and SQLite were rejected for production concurrency,
  durability, and migration limitations.
- PostgreSQL was not selected for version 1 because MariaDB is the approved
  deployment target; an adapter is not promised.
- A separate queue server was deferred because the Doctrine transport meets the
  initial operational model with fewer services.

## Validation

CI runs migrations and integration/concurrency tests against the supported
MariaDB matrix. Doctor checks connectivity, version, pending migrations,
constraints/indexes, suppression integrity, backup age, and restore evidence.

Reference: https://mariadb.org/about/#maintenance-policy
