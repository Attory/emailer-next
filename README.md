# Emailer Ripe

Emailer Ripe 1.3.1 includes guided Ubuntu 22.04/24.04 Apache and Debian 13 Nginx VPS installers,
ordinary Ripe 1.0.6/1.1.0/1.2.1/1.2.2 updates, and Light 1.0.0/1.1.0/1.2.0/1.2.1/1.2.2/1.2.3 upgrades with verified recovery and
explicit acceptance/resume. SMTP is optional during fresh setup.

- [Beginner installation](docs/guides/emailer-ripe-install.md)
- [Light to Ripe upgrade](docs/guides/emailer-light-to-ripe-upgrade.md)
- [User manual](docs/guides/emailer-ripe-manual.md)
- [Operator and troubleshooting reference](docs/guides/emailer-ripe-operator.md)
- [HTML/PDF downloads](docs/guides/README.md)

Download the private official `emailer-ripe-1.3.1-setup.run` through an authorized
channel, upload that one file to the server, then start `sudo sh emailer-ripe-1.3.1-setup.run`.
The guide explains DNS and host permission. The installer supplies runtime,
verification, database, secrets, isolated services, HTTPS, backups and launcher.
Customers do not need Docker, Composer, Mailpit or manual service definitions.

## Maintainer development bootstrap

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

See the [1.0.5 implementation record](docs/RELEASE-1.0.5-IMPLEMENTATION.md)
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

Apache installations on Ubuntu can coexist with an unrelated Nginx service on port 8081. Setup verifies Apache owns all listeners on 80/443 and preserves Nginx configuration, listener and service. Use the new setup file to retry the 1.2.1 initial-check rejection; keep installation state and database files.

Ubuntu 26.04.1 amd64/systemd with Apache 2.4.66 and Virtualmin 8.3.0 is supported by Ripe 1.3.1. This profile uses distribution PHP 8.5.4 and MariaDB 11.8.6. See the beginner guide for scope and the public ACME limitation.
