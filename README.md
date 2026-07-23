# Emailer Next

Emailer Next is the clean-room successor to Emailer: a self-hosted email
campaign application for exactly one organisation per installation. This
repository currently contains the **Phase 0 design baseline only**. It has no
application runtime, setup wizard, database schema, web routes, worker, or mail
delivery capability.

## Safety status

- The reachable history starts with the approved specification starter and
  contains documentation and placeholder configuration only.
- Legacy source, history, templates, dependencies, credentials, and private
  records are prohibited.
- No live SMTP, production service, deployment, release, or DNS operation is
  part of Phase 0.
- Symfony scaffolding begins only in a separately approved Phase 1 job.

See the [Phase 0 record](docs/phase-0-report.md) for evidence and open
decisions.

## Design map

- [Master specification](docs/spec/EMAILER-NEXT-MASTER-SPEC.md)
- [Bounded phase jobs](docs/spec/PHASE-JOBS.md)
- [Architecture](docs/architecture.md)
- [Module boundaries](docs/module-boundaries.md)
- [Data model](docs/data-model.md)
- [Route inventory](docs/route-inventory.md)
- [Threat model](docs/threat-model.md)
- [Security model](docs/security-model.md)
- [Quality gates](docs/quality-gates.md)
- [Implementation roadmap](docs/implementation-roadmap.md)
- [Open decisions](docs/open-decisions.md)
- [Architecture decisions](docs/adr/)

The remaining required product and operator documents are indexed in
[the documentation status page](docs/README.md).

## Working in this repository

Read [development.md](docs/development.md) and the
[clean-room checklist](docs/spec/CLEAN-ROOM-CHECKLIST.md) before every phase.
Each phase is a separate reviewed job and must stop at its stated boundary.
Never place a real credential, recipient address, private template, production
log, backup, export, or key in the tree, history, fixtures, screenshots, prompts,
or test output.
