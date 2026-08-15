# Operator runbook

**Status:** Phase 2 setup/local-platform runbook; production operations are not
implemented.

## Current repository state

Phases 1–2 contain the Symfony scaffold, locked dependencies, local
application/MariaDB/Mailpit Compose stack, one baseline migration, quality
pipeline, coarse liveness, logging redaction, and an inspected
production-shaped artifact, plus filesystem ownership proof and setup-state
controls. There is no completed product installation, account,
campaign, delivery worker, release, or deployment to operate.

Before any work, confirm the repository path and clean status without opening
credential files:

```bash
pwd
git status --short --branch
git log --oneline --decorate --all --graph
git remote
```

Do not fetch from, inspect, or add a legacy/temporary source repository. Do not
print remote URLs that may contain embedded credentials; resolve remote
provenance through an operator-approved, redacted process.

## Phase entry checklist

1. Read the master specification, bounded phase job, and clean-room checklist.
2. Confirm one clean root history, expected refs, no submodule/vendor/archive,
   and no unexplained unreachable Git objects.
3. Confirm the phase's external-service boundary and fake-only test endpoints.
4. Run current-tree/history secret, private-key, and private-data scans.
5. Identify migrations and manual actions before implementation.

The executable Phase 1 commands and limitations are in
[quality-gates.md](quality-gates.md). Results are recorded in
[phase-1-report.md](phase-1-report.md).

## Local platform operations

Bootstrap and validate:

```bash
./bin/bootstrap
```

Verify liveness without exposing detail:

```bash
curl --fail --silent http://127.0.0.1:8080/health/live
```

The exact response is `{"status":"ok"}`. It is only process liveness, not
database, migration, worker, key, suppression, or sending readiness.

Rerun the aggregate gate:

```bash
docker compose run --rm app composer check
```

Stop the local stack without discarding its test volume:

```bash
docker compose down
```

Do not substitute a live SMTP hostname, add real runtime values, expose Compose
ports beyond loopback, or treat this stack as production.

## Production operating model (future)

When implemented, operators will maintain:

- PHP-FPM web application behind TLS with document root `public/`;
- MariaDB and reviewed migrations;
- private writable storage and external restrictive master-key file;
- one long-running CLI worker under systemd/Supervisor or bounded locked cron;
- CLI scheduler;
- log rotation, disk monitoring, encrypted backups, and restore drills;
- full daily/on-demand Doctor plus login-time cached checks.

Credentials are supplied outside source/history and never placed on process
command lines. Doctor findings provide redacted evidence and exact remediation
but never automatically change DNS, accounts, or secrets.

## Safe change procedure

Every implementation phase is a separate job. Review its entire diff, migration
up/down or rollback behavior, test results, generated artifacts, documentation,
and scan output. Stop at the phase boundary. Commit, merge, push, release,
deploy, DNS change, third-party account change, and production SMTP test each
require separate explicit authorization.

## Escalation

On suspected credential/private-data exposure, public path exposure, unexpected
mail, suppression failure, ambiguous/duplicate delivery, key loss, or restore
failure, stop risky work and follow
[incident-response.md](incident-response.md). Preserve redacted evidence; never
paste secrets, raw recipient lists, message bodies, or tokens into chat/issues.

## Phase 2 setup operations

- Inspect setup safely with `bin/console emailer:setup:install`; it performs
  preflight and stores no credential.
- Use `bin/console emailer:setup:reset --backup=/absolute/private/backup` only
  when intentionally reopening an installation. Production data additionally
  requires `--destructive` and interactive `yes`; non-interactive destructive
  reset fails closed.
- Never put a proof file, backup, master key, or runtime credential in Git or
  under `public/`. Setup logs may contain opaque challenge IDs/results only.
- A completed installation cannot yet be produced by this phase; Phase 3 owns
  final lock creation after full Doctor checks.

## Phase 2 migrations and manual actions

- Local bootstrap automatically applies
  `DoctrineMigrations\Version20260723000000`. It creates no business table;
  only Doctrine's migration metadata is persisted.
- Phase 2 adds no dependency and no migration.
- No production migration, credential, recipient data, service account, release,
  or deployment action is required or authorized.
- The worktree initially exposed a local `origin` reference. It was removed
  without accessing its target. A future remote may be added only after an
  operator confirms the approved clean destination; pushing remains a separate
  action.
- Operators who do not run bootstrap need take no action. To exercise the
  scaffold later, install Docker/Compose and run the documented command with
  synthetic local data only.
