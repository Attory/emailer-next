# Emailer Next

Emailer Next is the clean-room successor to Emailer: a self-hosted email
campaign application for exactly one organisation per installation. The
repository is implementing **Phase 12** and currently identifies its reviewed
candidate source as `0.4.0` with schema `20260828000000`. It adds persistent
full/read-only Doctor operations, a redacted operational dashboard,
configurable bounded retention, and off-by-default verified external backup
automation to the accepted Phase 11 feature set.

The accepted operator-signed `0.3.0` candidate remains outside Git. Phase 12
contains deterministic `0.4.0` candidate tooling but no operator-signed Phase
12 candidate has yet been published or deployed, and no production mail
service has been contacted.

## Safe local bootstrap

Docker with the Compose plugin and `curl` are required. From a reviewed clean
checkout:

```bash
./bin/bootstrap
```

The command builds the pinned PHP image, installs exactly from
`composer.lock`, starts MariaDB and Mailpit, runs `composer check`, and verifies
the liveness response. Application and Mailpit ports bind to loopback only;
MariaDB has no host port. The local stack uses synthetic placeholders and
Mailpit, never live SMTP.

See [Installation](docs/installation.md) and
[Development](docs/development.md) before changing the stack.

## Safety status

- The platform targets Symfony 7.4 LTS on tested PHP 8.4 and 8.5 branches.
- Test kernel startup rejects every mail DSN except credential-free Mailpit on
  port 1025, and CI repeats tests on an internal Docker network without external
  egress.
- Structured JSON logs pass through a central redaction processor.
- Composer validation/audit, formatting, static and boundary analysis,
  PHPUnit, MariaDB migration smoke, artifact exposure, and current/history
  secret/private-data scans are aggregated by `composer check`.
- Production artifacts and signed candidates are assembled from an explicit
  allowlist; neither CI nor a phase merge publishes or deploys them.
- Legacy source, history, templates, dependencies, credentials, and private
  records remain prohibited.

See the [Phase 12 report](docs/phase-12-report.md) for the current implementation
boundary and evidence. The [Phase 11 report](docs/phase-11-report.md) remains
the authoritative accepted base record.

## Documentation map

- [Master specification](docs/spec/EMAILER-NEXT-MASTER-SPEC.md)
- [Bounded phase jobs](docs/spec/PHASE-JOBS.md)
- [Clean-room checklist](docs/spec/CLEAN-ROOM-CHECKLIST.md)
- [Architecture](docs/architecture.md)
- [Module boundaries](docs/module-boundaries.md)
- [Threat model](docs/threat-model.md)
- [Security model](docs/security-model.md)
- [Quality gates](docs/quality-gates.md)
- [Implementation roadmap](docs/implementation-roadmap.md)
- [Open decisions](docs/open-decisions.md)
- [Architecture decisions](docs/adr/)

Each phase is a separately authorized job and must stop at its stated boundary.
Never place a real credential, recipient address, private template, production
log, backup, export, or key in the tree, history, fixtures, screenshots,
prompts, or test output.
