# Development

**Status:** Phase 0 process baseline; reproducible runtime setup is Phase 1.

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

Phase 0 has documentation and repository policy only. There is no Composer
project or runnable test suite. Do not scaffold Symfony until a separately
approved Phase 1 job.

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

Phase 1 will provide one tested bootstrap command, pinned dependencies, container
services, formatting/static-analysis tools, and `composer check`.
