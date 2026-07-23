# Operator runbook

**Status:** Phase 0 repository runbook; runtime operations are not implemented.

## Current repository state

Phase 0 contains specifications, architecture/security/operator documentation,
ADRs, and placeholder-only repository safeguards. There is no application,
dependency install, database migration, mail transport, worker, artifact,
release, or deployment to operate.

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

The exact Phase 0 commands and limitations are in
[quality-gates.md](quality-gates.md). Results are recorded in
[phase-0-report.md](phase-0-report.md).

## Runtime operating model (future)

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

## Phase 0 manual actions

- No migration, dependency install, external contact, credential, service,
  release, or deployment action is required.
- The inherited local, non-final `origin` reference was removed without
  accessing its target. A future remote may be added only after an operator
  confirms the approved clean destination; pushing remains a separate action.
