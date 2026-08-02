# Documentation status

The master specification is canonical. Architecture decision records (ADRs)
explain why a choice was locked; operational documents explain how that choice
is implemented and verified. A later phase may refine a document but may not
silently contradict the master specification or an accepted ADR.

**Current implementation boundary:** Phase 1 is complete. The repository is a
safe Symfony platform scaffold with a local MariaDB/Mailpit stack, CI and
quality gates, coarse liveness, structured redaction, and an inspected
production-shaped artifact. Product installation and all business features
remain unimplemented.

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

## Product and operator documents

| Document | Current implementation status |
| --- | --- |
| [Installation](installation.md) | Phase 1 safe local bootstrap; production setup remains Phases 2–3 |
| [First-run setup](first-run-setup.md) | Design only; Phases 2–3 |
| [Administrator guide](admin-guide.md) | Design only; Phase 4 onward |
| [Operator runbook](operator-runbook.md) | Phase 1 local platform operations |
| [Worker](worker.md) | Design only; Phase 6 |
| [Backup and restore](backup-restore.md) | Design only; Phase 8 |
| [Upgrade and rollback](upgrade-rollback.md) | Phase 1 migration baseline; operational tooling in Phase 8 |
| [Incident response](incident-response.md) | Baseline, including source/artifact/log exposure |
| [Privacy and retention](privacy-retention.md) | Design only; Phases 5–8 |
| [Development](development.md) | Phase 1 executable workflow |
| [Testing](testing.md) | Phase 1 executable tests and isolation policy |
| [Release](release.md) | Phase 1 artifact inspection only; no release mechanism |

Major decisions are under [docs/adr](adr/). Specifications under
[docs/spec](spec/) are not implementation output and must not be weakened by
downstream documentation.
