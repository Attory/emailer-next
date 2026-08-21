# Documentation status

The master specification is canonical. Architecture decision records (ADRs)
explain why a choice was locked; operational documents explain how that choice
is implemented and verified. A later phase may refine a document but may not
silently contradict the master specification or an accepted ADR.

**Current implementation boundary:** Phase 5 contacts, suppression, imports,
and templates are implemented over secure setup and administration. Campaigns,
workers, public recipient routes, and production delivery remain unimplemented.

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

## Product and operator documents

| Document | Current implementation status |
| --- | --- |
| [Installation](installation.md) | Phase 5 content administration; production deployment/SMTP unavailable |
| [First-run setup](first-run-setup.md) | Phase 3 secure wizard and permanent lock implemented |
| [Administrator guide](admin-guide.md) | Phase 5 contacts/imports/suppression/templates implemented |
| [Operator runbook](operator-runbook.md) | Phase 5 content administration and local platform operations |
| [Worker](worker.md) | Design only; Phase 6 |
| [Backup and restore](backup-restore.md) | Design only; Phase 8 |
| [Upgrade and rollback](upgrade-rollback.md) | Phase 1 migration baseline; operational tooling in Phase 8 |
| [Incident response](incident-response.md) | Baseline, including source/artifact/log exposure |
| [Privacy and retention](privacy-retention.md) | Phase 5 data minimization; later analytics/retention automation planned |
| [Development](development.md) | Phase 5 executable workflow |
| [Testing](testing.md) | Phase 5 executable tests and isolation policy |
| [Release](release.md) | Phase 1 artifact inspection only; no release mechanism |

Major decisions are under [docs/adr](adr/). Specifications under
[docs/spec](spec/) are not implementation output and must not be weakened by
downstream documentation.
