# Documentation status

The master specification is canonical. Architecture decision records (ADRs)
explain why a choice was locked; operational documents explain how that choice
is implemented and verified. A later phase may refine a document but may not
silently contradict the master specification or an accepted ADR.

**Current implementation boundary:** Phase 9 client-installable `0.1.0` MVP
candidate acceptance, including deterministic signed packaging, clean-install
and update rehearsals, accessibility, bounded load, and current platform
revalidation. No package has been published or deployed.
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
| [Phase 9 report](phase-9-report.md) | MVP candidate, reproducibility, installation, accessibility, load, and acceptance evidence |

## Product and operator documents

| Document | Current implementation status |
| --- | --- |
| [Installation](installation.md) | Phase 9 clean-server `0.1.0` candidate procedure |
| [First-run setup](first-run-setup.md) | Phase 3 secure wizard and permanent lock implemented |
| [Administrator guide](admin-guide.md) | Phase 9 MVP workflows and approval evidence |
| [Operator runbook](operator-runbook.md) | Phase 9 candidate plus Doctor/recovery/backup/update operations |
| [Worker](worker.md) | Phase 9 MVP readiness and maintenance behavior accepted |
| [Backup and restore](backup-restore.md) | Phase 9 MVP encrypted local drill accepted |
| [Upgrade and rollback](upgrade-rollback.md) | Phase 9 supported candidate/update window |
| [Incident response](incident-response.md) | Baseline, including source/artifact/log exposure |
| [Privacy and retention](privacy-retention.md) | Phase 8 bounded housekeeping; full automation remains Phase 12 |
| [Development](development.md) | Phase 9 executable workflow |
| [Testing](testing.md) | Phase 9 acceptance tests and isolation policy |
| [Release](release.md) | Phase 9 candidate mechanism and release boundary |
| [Release signing](release-signing.md) | Key custody, fingerprinting, rotation, compromise, and support window |

Major decisions are under [docs/adr](adr/). Specifications under
[docs/spec](spec/) are not implementation output and must not be weakened by
downstream documentation.
