# Documentation status

The master specification is canonical. Architecture decision records (ADRs)
explain why a choice was locked; operational documents explain how that choice
is implemented and verified. A later phase may refine a document but may not
silently contradict the master specification or an accepted ADR.

**Current implementation boundary:** Phase 8 critical Doctor/send gates,
encrypted backup/restore, recovery, housekeeping, and signed immutable update
tooling are implemented over the Phase 7 delivery baseline. Phase 9 release
candidate and production-profile acceptance remain.
The approved roadmap targets a client-installable MVP at Phase 9, followed by
installable feature/operations updates through Version 1 at Phase 13.

## Design and evidence

| Document | Purpose |
| --- | --- |
| [Architecture](architecture.md) | System shape, implemented baseline, trust boundaries, and runtime flows |
| [Module boundaries](module-boundaries.md) | Ownership, dependency rules, and enforcement |
| [Data model](data-model.md) | Planned entities plus current schema boundary |
| [Route inventory](route-inventory.md) | Implemented and planned HTTP/CLI surface |
| [Threat model](threat-model.md) | Assets, actors, threats, mitigations, and Phase 1 control status |
| [Security model](security-model.md) | Enforceable security invariants and current controls |
| [Quality gates](quality-gates.md) | CI order, commands, scan scope, and artifact gates |
| [Implementation roadmap](implementation-roadmap.md) | Phase-by-phase delivery boundaries |
| [Open decisions](open-decisions.md) | Resolved Phase 1 decisions and bounded future decisions |
| [Phase 0 report](phase-0-report.md) | Foundation clean-room evidence |
| [Phase 1 report](phase-1-report.md) | Platform implementation and executed evidence |
| [Phase 2 report](phase-2-report.md) | Filesystem ownership proof and setup-state evidence |
| [Phase 3 report](phase-3-report.md) | Secure wizard, key, Owner, Mailpit, migration, and finalization evidence |
| [Phase 4 report](phase-4-report.md) | Identity, session, MFA, recovery, authorization, and audit evidence |
| [Phase 5 report](phase-5-report.md) | Contact/import/suppression/template implementation and gate evidence |
| [Phase 6 report](phase-6-report.md) | Campaign state, durable delivery, transport, worker, and gate evidence |
| [Phase 7 report](phase-7-report.md) | Recipient-token, unsubscribe, public-route, and privacy evidence |
| [Phase 8 report](phase-8-report.md) | Doctor, recovery, backup/restore, housekeeping, and signed-update evidence |

## Product and operator documents

| Document | Current implementation status |
| --- | --- |
| [Installation](installation.md) | Phase 8 operations tooling; first accepted client package remains Phase 9 |
| [First-run setup](first-run-setup.md) | Phase 3 secure wizard and permanent lock implemented |
| [Administrator guide](admin-guide.md) | Phase 8 authenticated operations views implemented |
| [Operator runbook](operator-runbook.md) | Phase 8 Doctor/recovery/backup/update operations |
| [Worker](worker.md) | Phase 8 readiness and maintenance behavior implemented |
| [Backup and restore](backup-restore.md) | Phase 8 encrypted local implementation and drill |
| [Upgrade and rollback](upgrade-rollback.md) | Phase 8 tooling implemented; first candidate in Phase 9 |
| [Incident response](incident-response.md) | Baseline, including source/artifact/log exposure |
| [Privacy and retention](privacy-retention.md) | Phase 8 bounded housekeeping; full automation remains Phase 12 |
| [Development](development.md) | Phase 8 executable workflow |
| [Testing](testing.md) | Phase 8 executable tests and isolation policy |
| [Release](release.md) | Phase 8 package mechanism; no candidate published |

Major decisions are under [docs/adr](adr/). Specifications under
[docs/spec](spec/) are not implementation output and must not be weakened by
downstream documentation.
