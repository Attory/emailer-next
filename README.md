# Emailer Next

Emailer Next is the clean-room successor to Emailer: a self-hosted email
campaign application for exactly one organisation per installation. The
repository is preparing the bounded `1.0.4` list-download maintenance patch
with schema `20261008000000`. The completed Phase 13 work added Version-1
acceptance tooling, a protected interactive CLI installer, transactional
master-key rotation, license and browser/accessibility policy, and client-grade
`1.0.0` installation/update manuals.

The Hatha Yoga Space installation currently points to `1.0.3`. This `1.0.4`
branch is a development candidate only. It is not merged, signed with an
operator key, published, or deployed. The private list recovery files are
outside this repository and never enter package artifacts.

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

See the [1.0.4 implementation record](docs/RELEASE-1.0.4-IMPLEMENTATION.md)
for this bounded change. Earlier phase and release reports remain the
authoritative incremental record.

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
