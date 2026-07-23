# Documentation status

The master specification is canonical. Architecture decision records (ADRs)
explain why a choice was locked; operational documents explain how that choice
will be implemented and verified. A later phase may refine a document but may
not silently contradict the master specification or an accepted ADR.

## Phase 0 design documents

| Document | Purpose |
| --- | --- |
| [Architecture](architecture.md) | System shape, trust boundaries, and runtime flows |
| [Module boundaries](module-boundaries.md) | Ownership and dependency rules |
| [Data model](data-model.md) | Initial entities, relations, and invariants |
| [Route inventory](route-inventory.md) | Planned HTTP and CLI surface |
| [Threat model](threat-model.md) | Assets, actors, threats, and mitigations |
| [Security model](security-model.md) | Enforceable security invariants |
| [Quality gates](quality-gates.md) | CI order, scan scope, and release gates |
| [Implementation roadmap](implementation-roadmap.md) | Phase-by-phase delivery boundaries |
| [Open decisions](open-decisions.md) | Decisions intentionally deferred to a bounded phase |
| [Phase 0 report](phase-0-report.md) | Clean-room evidence and check results |

## Required product and operator documents

These documents exist now as design baselines. Commands that require an
application are deliberately deferred and marked with the phase that must add
and test them.

| Document | First implementation phase |
| --- | --- |
| [Installation](installation.md) | Phase 1 |
| [First-run setup](first-run-setup.md) | Phases 2–3 |
| [Administrator guide](admin-guide.md) | Phase 4 |
| [Operator runbook](operator-runbook.md) | Phase 1, expanded through Phase 9 |
| [Worker](worker.md) | Phase 6 |
| [Backup and restore](backup-restore.md) | Phase 8 |
| [Upgrade and rollback](upgrade-rollback.md) | Phase 8 |
| [Incident response](incident-response.md) | Phase 1, expanded through Phase 9 |
| [Privacy and retention](privacy-retention.md) | Phases 5–8 |
| [Development](development.md) | Phase 0, expanded in Phase 1 |
| [Testing](testing.md) | Phase 0, expanded in every phase |
| [Release](release.md) | Phase 1, finalized in Phase 9 |

Major decisions are under [docs/adr](adr/). Specifications under
[docs/spec](spec/) are not implementation output and must not be weakened by
downstream documentation.
