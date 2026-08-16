# Operator runbook

**Status:** Phase 4 secure-administration/local-platform runbook; production
delivery operations remain unimplemented.

## Current repository state

Phases 1–4 contain the Symfony scaffold, locked dependencies, local
application/MariaDB/Mailpit Compose stack, one baseline migration, quality
pipeline, coarse liveness, logging redaction, and an inspected
production-shaped artifact, plus filesystem ownership proof and setup-state
controls. Phase 3 creates the organisation, initial Owner, exact local Mailpit
evidence, safe policy defaults, and permanent installation lock. Phase 4 adds
individual accounts, secure authentication, server sessions, recovery, the
admin shell, and audit. There is no contact/campaign/delivery worker, release,
or deployment to operate.

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
- `bin/console emailer:setup:reset` is intentionally unavailable in Phase 2:
  no supported integrity-authenticated backup artifact exists yet. Do not use a
  marker file or self-declared digest as backup authority. Phase 8 must provide
  the producer and validator before reset can pass its backup gate; the
  production-data, `--destructive`, and interactive safeguards remain in place.
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

## Phase 3 setup operations

1. Supply database/private/key paths through the external runtime; do not copy
   real values into this repository or pass them on command lines. Production
   requires an absolute key path outside the full application/release/public
   tree with a safe real ancestor chain. Omission or an in-tree/link-directed
   path fails closed.
2. Complete the existing filesystem proof over HTTPS, then follow the database,
   organisation, Owner, local-mail, policy, and finalization pages.
3. Save optional TOTP recovery material when displayed. It cannot be
   redisplayed from the database, logs, cache, queue, or setup session.
4. Confirm Mailpit captured the marked synthetic message. The Phase 3 probe is
   intentionally plaintext and unauthenticated; the stored row records that
   exact fact and does not claim TLS verification. TLS/auth inputs and live SMTP
   are rejected, so Phase 3 cannot validate a production provider.
5. Before completion, separately protect the external master key. The ordinary
   backup design excludes it; the supported encrypted recovery-package command
   remains Phase 8.
6. After completion, expect every `/setup` route to return 404. Do not delete
   the lock or edit database completion state to reopen setup.

All setup writers and finalization share one lifecycle lock. A delayed writer
finishes or fails before final lock publication; after publication, database,
key, organisation, Owner/MFA, mail, secret, and policy setup writers fail closed.
Owner resumption requires the original password, and skipping/restarting MFA
removes pending inactive enrollment material.

`emailer:secrets:replace` and `emailer:secrets:rotate-master-key` deliberately
return failure and mutate nothing. They are reserved interfaces, not emergency
recovery tools. Phase 3 contains its setup-schema migration plus the bounded
mail-evidence integrity migration and no new dependency.

## Phase 4 identity operations

1. Apply `Version20260817000000` and `Version20260818000000` through the normal
   migration gate. Configure only the actual trusted proxy hops and a strict
   `TRUSTED_HOSTS` expression for the canonical host. Serve the admin surface
   over HTTPS; forwarded host values are deliberately ignored. Provision
   the final proxy to replace `X-Forwarded-For` with its directly observed
   client address; never append an inbound value. Provision
   `APP_SECRET` with at least 16 bytes of high-entropy secret material. Rotating
   it invalidates all sessions and changes throttle/source HMAC identities, so
   rotation requires a planned maintenance window and investigation of orphaned
   historical throttle rows.
2. Sign in at `/login` with an individual account. There is no self-registration
   or shared account. Owners may create all roles; Administrators cannot create,
   promote, demote, disable, or delete an Owner.
3. Use `/admin/sessions` to review and revoke the current account's active
   sessions. Sensitive session, user, password, and MFA mutations first require
   the matching recent re-authentication purpose and always require CSRF.
4. MFA recovery codes are shown only at enrollment/regeneration, stored as
   hashes, and consumed once. Password-reset mail is synthetic Mailpit-only in
   development/CI; a request response never confirms account existence.
5. Use `/admin/audit` for redacted append-only identity evidence. There is no
   edit/delete route. A database-trigger rejection is expected if any tool
   attempts to mutate an audit row.

Do not clear throttle/session/audit tables as routine remediation. A temporary
lock expires without extending on blocked requests. Revoked session payloads
are erased immediately; their HMAC tombstones remain for seven days beyond
revocation before bounded garbage collection. Investigate repeated
lockouts using redacted audit evidence; never ask for a password, MFA seed,
recovery value, reset token, raw session ID, or full cookie.
