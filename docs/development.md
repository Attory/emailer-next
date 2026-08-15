# Development

**Status:** Phase 2 executable setup baseline.

## Clean-room rule

Implementation uses only the approved written specifications, accepted ADRs,
and operator-approved screenshots/descriptions containing no secret or personal
data. Never clone, inspect, import, copy, vendor, or add as a remote any legacy
Emailer or temporary source repository. Do not reuse its code, Git objects,
templates, dependencies, queue/log/data files, tests, or private records.

Never place a real credential, private key, recovery value, administrator
password, recipient address/list, production token, private template, log,
backup, export, or screenshot in source, history, prompts, fixtures, snapshots,
CI output, or build artifacts.

## Current state

Phases 1–2 provide Symfony 7.4, a locked Composer project, Docker Compose services
for the application/MariaDB/Mailpit, executable quality gates, coarse liveness,
structured redaction, an inspected production-shaped artifact, and the bounded
setup ownership/state flow. It has no credential wizard, authentication,
contacts, templates, campaigns, delivery worker, or production sending.

## Phase workflow

1. Read the canonical master spec, current phase job, clean-room checklist,
   relevant ADRs, and open decisions.
2. Confirm repository provenance/refs and run pre-change scans.
3. Revalidate time-sensitive choices against official primary documentation.
4. Implement only the bounded phase.
5. Add tests for its security properties, negative paths, races, and redaction.
6. Run phase checks plus the aggregate checks available.
7. Update architecture, threat/security model, routes, data model, operator
   documents, roadmap, and decisions affected.
8. Review all diffs, migrations, generated output, and scan findings.
9. Report exact results, incomplete work, migrations/manual actions, then stop.

Do not infer permission to commit, push, merge, release, deploy, contact a
production service, rotate a provider credential, or change DNS/account state.

## Design conventions

- Code will live under `src/Module/<Name>/` using the layering and contracts in
  [module-boundaries.md](module-boundaries.md).
- Business rules live in domain/application services, never controller,
  template, entity, command, or JavaScript.
- Use opaque/UUIDv7 identifiers and UTC storage.
- No `tenant_id` or tenant-switching abstraction.
- Browser mutation is unsafe-method + authorization + CSRF.
- Queue messages are identifiers only.
- Tests use MariaDB and Mailpit; SQLite/live SMTP are not substitutes.
- New public routes, secret types, exports, uploads, outbound services, or
  cross-module dependencies require documentation/threat review first.

## Local configuration

`.env.example` contains placeholders only. Local values belong in ignored files
or runtime configuration. Production values never belong in a developer
workspace. Do not print environment files or use real SMTP for convenience.

The supported path needs no copied environment file:

```bash
./bin/bootstrap
```

This builds and starts the loopback-only development stack, performs a clean
locked dependency install, runs every current gate, and verifies
`http://127.0.0.1:8080/health/live`. Mailpit's local UI is available at
`http://127.0.0.1:8025`; MariaDB is reachable only on the Compose network.

After the stack is ready, rerun the aggregate gate with:

```bash
docker compose run --rm app composer check
```

Use `docker compose down` to stop containers while preserving the local
database volume. Removing volumes discards local test state and must be an
intentional operator action.

## Toolchain

- PHP-CS-Fixer performs deterministic style checks.
- PHPStan runs at maximum level with strict, Symfony, and Doctrine extensions.
- Deptrac and the project boundary check enforce modular-monolith direction,
  framework-free Domain code, Presentation controller placement, and the
  prohibition on tenant discriminators.
- PHPUnit provides unit, framework/HTTP, MariaDB, Mailpit, SMTP-policy, and
  redaction tests.
- Gitleaks 8.30.1 is checksum-pinned in the application image; exact
  line-specific third-party false positives are reviewed in `.gitleaksignore`.

Host-side tool installation is not the supported bootstrap. The container image
owns runtime extensions and tool versions; CI builds the same Dockerfile.
