# ADR 0001: Single-organisation modular monolith

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

Each installation serves one organisation on one server but supports several
individual administrator accounts. The product needs clear security and domain
boundaries without the operational cost and failure modes of distributed
services. Multi-tenancy would add cross-client authorization and data-isolation
risk that the product does not need.

## Decision

Build one deployable Symfony modular monolith with the modules listed in the
master specification. A database and configuration belong to exactly one
organisation. Do not add `tenant_id`, tenant switching, shared client
infrastructure, or a cross-installation control plane.

Modules own their models and tables and interact through explicit application
contracts or redacted events. Boundaries are enforced by static analysis and
architecture tests.

## Consequences

- Deployment, backup, scaling, and incident scope remain installation-local.
- Multiple administrators are identities and roles, not tenants.
- Modules can be extracted later only with a new ADR and threat-model review.
- Some cross-module workflows require carefully designed contracts and
  transactional orchestration.

## Security and privacy impact

The decision removes an entire class of cross-tenant authorization and data
leakage risks. It does not remove role checks between administrators. A backup
or server still contains one organisation's private data and requires full
protection.

## Alternatives considered

- A multi-tenant SaaS architecture was rejected as out of scope and riskier.
- Microservices were rejected because they add network trust, distributed
  transactions, secret distribution, and operational burden without a version
  1 requirement.
- An unstructured monolith was rejected because it makes authorization,
  persistence ownership, and testing boundaries difficult to enforce.

## Validation

Static checks reject tenant-discriminator symbols and forbidden module imports.
Schema and route reviews confirm no tenant selector. Installation, backup, and
Doctor tests operate on one organisation only.
