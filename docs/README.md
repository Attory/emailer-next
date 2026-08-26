# Documentation status

The master specification is canonical. Architecture decision records (ADRs)
explain why a choice was locked; operational documents explain how that choice
is implemented and verified. A later phase may refine a document but may not
silently contradict the master specification or an accepted ADR.

**Current implementation boundary:** accepted Phase 12 advanced operations and
the operator-signed `0.4.0` update candidate. The candidate supports clean
install and update from supported `0.3.x`, but has not been tagged, published,
deployed, or installed on a client server. Phase 13 remains unstarted and
requires separate authorization.
The approved roadmap targets a client-installable MVP at Phase 9, followed by
installable feature/operations updates through Version 1 at Phase 13.

## Design and evidence

| Document | Purpose |
| --- | --- |
| [Architecture](architecture.md) | System shape, implemented baseline, trust boundaries, and runtime flows |
| [Module boundaries](module-boundaries.md) | Ownership, dependency rules, and enforcement |
| [Data model](data-model.md) | Planned entities plus current schema boundary |
| [Route inventory](route-inventory.md) | Implemented and planned HTTP/CLI surface |
| [Threat model](threat-model.md) | Assets, actors, threats, mitigations, and controls through Phase 11 |
| [Security model](security-model.md) | Enforceable security invariants and current controls |
| [Quality gates](quality-gates.md) | CI order, commands, scan scope, and artifact gates |
| [Implementation roadmap](implementation-roadmap.md) | Phase-by-phase delivery boundaries |
| [Open decisions](open-decisions.md) | Resolved decisions through Phase 11 and bounded future decisions |
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
| [Phase 10 report](phase-10-report.md) | View-in-browser, optional tracking, privacy, update, and gate evidence |
| [Phase 11 report](phase-11-report.md) | Optional RSVP, operator-signed `0.3.0` candidate, independent review, CI, and merge evidence |
| [Phase 12 report](phase-12-report.md) | Full Doctor, operational dashboard, configurable retention, external backup automation, operator-signed `0.4.0` candidate, independent review, CI, and merge evidence |

## Product and operator documents

| Document | Current implementation status |
| --- | --- |
| [Installation](installation.md) | Clean-server installation contract plus current candidate boundary |
| [First-run setup](first-run-setup.md) | Phase 3 secure wizard and permanent lock implemented |
| [Administrator guide](admin-guide.md) | Implemented administration, tracking, and RSVP workflows through Phase 11 |
| [Operator runbook](operator-runbook.md) | Phase 12 Doctor, scheduler, recovery, backup, retention, and update operations |
| [Worker](worker.md) | Current delivery readiness and maintenance behavior |
| [Backup and restore](backup-restore.md) | Encrypted local and approved external backup/restore operations |
| [Upgrade and rollback](upgrade-rollback.md) | Phase 12 clean-install and supported `0.3.x` to `0.4.0` update window |
| [Incident response](incident-response.md) | Baseline, including source/artifact/log exposure |
| [Privacy and retention](privacy-retention.md) | Phase 12 configurable bounded retention and durable exclusions |
| [Development](development.md) | Executable workflow and current Phase 12 boundary |
| [Testing](testing.md) | Acceptance coverage and isolation policy through Phase 12 |
| [Release](release.md) | Current signed-candidate mechanism and publication boundary |
| [Release signing](release-signing.md) | Key custody, fingerprinting, rotation, compromise, and support window |

Major decisions are under [docs/adr](adr/). Specifications under
[docs/spec](spec/) are not implementation output and must not be weakened by
downstream documentation.
