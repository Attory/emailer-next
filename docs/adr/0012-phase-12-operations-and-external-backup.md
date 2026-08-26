# ADR 0012: Phase 12 operations coordination and external backup boundary

- Status: Accepted for Phase 12 candidate
- Date: 2026-08-26

## Context

Daily Doctor, configurable bounded retention, external backup automation, and
a single operational dashboard span several data-owning modules. Putting these
queries and mutations in an Identity dashboard controller or Health probe would
break ownership boundaries. A first external destination also needs useful
recovery separation without introducing provider credentials, network APIs, or
an unreviewed cloud trust boundary.

## Decision

Add a narrow `Operations` application-coordination module. It owns only the
singleton bounded policy and stable scheduler-run evidence. Its scheduler
consumes published contracts from Health, Housekeeping, and Backup under one
cross-process lock. Its dashboard composes tagged, fixed-shape redacted status
providers implemented by the owning modules.

Classify Doctor probes explicitly as lightweight and/or sending-gating. Login
uses a 15-minute persisted lightweight cache. Full runs are daily/on-demand.
Current findings persist separately from immutable run history, and an
acknowledgement never changes the `sending_blocked` projection.

Approve one external adapter: an existing operator-provisioned absolute
filesystem mount outside both the release and private-state trees. The path is
external runtime configuration and is never stored or displayed. Automation is
off by default. Transfers require exclusive regular-file creation, restrictive
permissions, exact byte count, SHA-256 read-back verification, and redacted
database evidence. Automatic pruning is bounded and preserves the newest
verified backup.

## Consequences

- Data owners retain their tables and expose only contract-shaped aggregates.
- The scheduler can be invoked every minute while each operation observes its
  own due interval and non-overlap evidence.
- Mounted-filesystem availability or integrity failure becomes a visible
  Doctor/operations failure without disclosing its path.
- The adapter does not claim immutable, provider-independent, or off-site
  custody. Operators must supply mount protections and restore drills.
- Any cloud/object-store adapter, credential type, network egress, unattended
  update behavior, or new public route requires a later threat-model review and
  explicit authorization.
