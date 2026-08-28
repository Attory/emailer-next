# Operator runbook

**Status:** Phase 13 Version-1 acceptance runbook. The accepted `0.4.0`
candidate remains unpublished; `1.0.0` remains pending final acceptance.

## Installing or updating to 1.0.0

For a new client server, follow
[CLIENT-INSTALL-1.0.0.md](CLIENT-INSTALL-1.0.0.md) in order. For an existing
accepted exact `0.1.0`, `0.2.0`, `0.3.0`, or `0.4.0` installation, follow
[CLIENT-UPDATE-1.0.0.md](CLIENT-UPDATE-1.0.0.md). Do not substitute an unsigned
archive, skip the independent public-key fingerprint, unpack before standalone
verification, or resume delivery before Doctor and restore evidence pass.

## Updating 0.3.x to 0.4.0

Use the separately supplied `CLIENT-UPDATE-0.4.0.md` and signed
`emailer-next-0.4.0.zip`. The supervised updater quiesces delivery, creates and
verifies the safety backup, migrates to `Version20260828000000`, switches the
immutable pointer, and runs Doctor. Retention and external backup automation
remain disabled until their policies and runtime destination are explicitly
approved.

## Phase 12 daily operations

Run `php bin/console emailer:operations:run` at least every minute from the
same non-root service identity as the scheduler. Its cross-process lock runs a
full Doctor at the configured daily interval and only runs retention or backup
automation when explicitly enabled and due. Results are stable redacted codes
in `operations_run`; failure returns a non-zero exit without printing paths,
credentials, provider responses, or recipient data.

Login performs only cached lightweight read checks. Use
`emailer:doctor:run --json` or the Doctor page for a current full report.
Acknowledgement records operator awareness and never disables a sending gate.

To enable external backup automation, first provision an absolute restrictive
directory outside both the release and `EMAILER_PRIVATE_DIR` trees, expose it
only as `EMAILER_BACKUP_DESTINATION_DIR`, confirm the Backups page reports the
filesystem adapter available, then have an Owner save the policy after recent
re-authentication. Perform and record isolated restore drills at the configured
cadence. There is no cloud credential, browser restore, or unattended update
path.

## Updating 0.2.x to 0.3.0

Use the separately supplied `CLIENT-UPDATE-0.3.0.md` and signed
`emailer-next-0.3.0.zip`. Inspect first, then run the existing supervised
`emailer:release:update` workflow. It quiesces delivery, verifies and backs up,
migrates to `Version20260827000000`, switches atomically, and runs Doctor.
RSVP remains off until separately enabled globally and on a draft campaign.

## Updating 0.1.0 to 0.2.0

Inspect and apply the signed `emailer-next-0.2.0.zip` using the existing release
CLI. It accepts `0.1.0` as its minimum source and migrates through
`Version20260826000000`. Preserve the automatic pre-update backup: this schema
is not pointer-rollback compatible, so failure restores that backup before the
previous release pointer is reinstated.

After a healthy update, visit **Analytics** as an Administrator. Open and click
tracking are off until independently enabled. The retention period is 1–365
days, and policy changes apply only to campaigns approved later. Use
**Retention → Preview** before applying deletion.

## Current repository state

Phases 1–7 contain the Symfony scaffold, locked dependencies, local
application/MariaDB/Mailpit Compose stack, one baseline migration, quality
pipeline, coarse liveness, logging redaction, and an inspected
production-shaped artifact, plus filesystem ownership proof and setup-state
controls. Phase 3 creates the organisation, initial Owner, exact local Mailpit
evidence, safe policy defaults, and permanent installation lock. Phase 4 adds
individual accounts, secure authentication, server sessions, recovery, the
admin shell, and audit. Phase 5 adds contacts, lists, private imports, durable
suppression, immutable templates, and safe raster assets. Phase 6 adds campaign
delivery and supervised workers. Phase 7 adds mandatory unsubscribe links,
cookie-free public handling, and immediate suppression. Phase 8 adds critical
readiness gates, encrypted local backup/restore and key recovery, bounded
housekeeping, and CLI-only signed immutable update tooling. Phase 9 adds the
first `0.1.0` candidate workflow, current platform matrix, accessibility/load
evidence, and production-profile rehearsal without publishing or deploying it.

The approved delivery sequence is Phase 6 campaigns/worker, Phase 7 safe
unsubscribe, Phase 8 critical operations plus signed package/update tooling,
and Phase 9 client-installable `0.1.0` acceptance. Later phases must arrive as
tested updates (`0.2.0`–`1.0.0`). This sequence is a plan, not authorization to
install, publish, deploy, or contact a production provider.

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

## Production operating model

Operators maintain:

- PHP-FPM web application behind TLS with document root `public/`;
- MariaDB and reviewed migrations;
- private writable storage and external restrictive master-key file;
- one long-running CLI worker under systemd/Supervisor or bounded locked cron;
- CLI scheduler;
- log rotation, disk monitoring, encrypted backups, and restore drills;
- full daily/on-demand Doctor plus login-time cached checks.
- immutable semantic-versioned release directories, an atomic current-release
  pointer, and external client configuration/private state;
- an explicit CLI-only signed update flow with maintenance lock, quiescence,
  verified pre-update backup, migrations, health checks, and safe
  rollback-or-restore behavior.

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
  preflight and stores no credential. Use `--complete` only in a protected
  interactive terminal when the CLI route is chosen. The command never accepts
  secrets as options, never prints them, and uses a local fake Mailpit probe.
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

`emailer:secrets:replace` remains fail closed; use the authenticated Owner mail
configuration workflow for transport-secret replacement. Master-key rotation
is now a separately controlled maintenance operation:

1. stop new delivery and wait for active claims to reach zero, then stop
   PHP-FPM, worker, scheduler, and operations processes so only the protected
   rotation CLI can access secrets during the transaction;
2. confirm the separately held current recovery package is readable and its
   passphrase is available to the recovery owner;
3. run `bin/console emailer:secrets:rotate-master-key` from the service account
   in a protected terminal and complete Owner password/MFA re-authentication
   plus the hidden current recovery-package passphrase check;
4. retain the reported verified pre-rotation backup and old recovery package
   until the change is accepted;
5. move the old recovery package to separately controlled historical custody,
   ensure the configured recovery output path is empty, then run
   `bin/console emailer:secrets:export-recovery-key` with a new independently
   stored passphrase;
6. create and verify a new encrypted backup, run full Doctor, and perform the
   documented secret-dependent smoke checks, restart every application process,
   and rerun Doctor before resuming delivery.

The rotation journal remains inside the external mode-`0700` key directory.
Every key/journal content write is flushed and file-`fsync`ed before publication;
every journal/key link, rename, and unlink is followed by an `fsync` of that
directory before the protocol advances, so contents and namespace ordering
survive host power loss as well as process interruption. Initial key creation
and recovery use the same file-before-directory barrier order. The journal is reconciled
automatically against the committed opaque audit marker after a process crash
or ambiguous commit. A concurrent process is denied key access
while the maintenance lock is held. Any failure keeps delivery stopped. If
journal reconciliation reports failure, do not retry blindly: preserve the key
directory, restore from the separately held recovery material only through the
incident procedure, authenticate the reported pre-rotation backup, and follow
incident response. Rotation never contacts a provider and the audit contains
counts/opaque IDs only.

## Phase 4 identity operations

1. Apply `Version20260817000000` and `Version20260818000000` through the normal
   migration gate. Configure only the actual trusted proxy hops and a strict
   `TRUSTED_HOSTS` expression for the canonical host, quoting the entire value
   in a systemd environment file so literal regex backslashes survive parsing.
   Serve the admin surface
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

## Phase 5 content operations

1. Apply `Version20260819000000` through the normal migration gate and verify
   DOM/GD plus JPEG, PNG, and WebP support. Keep the import directory private.
2. Treat CSV preview as a mandatory validation step. Reports contain only row
   numbers and safe reasons; committed imports remove their source upload, and
   unfinished uploads expire after two hours.
3. Do not remove or bypass a suppression through the database. Phase 5 offers
   an add-only administrative path and rechecks suppression at import commit.
4. Full-address reveal/export is Administrator-only, requires recent
   re-authentication and CSRF, and produces an audit event. Export files remain
   private operational artifacts even though formula-leading cells are escaped.
5. Template publication selects an immutable sanitized version. The test
   action is fake-only and sends no mail. Uploaded images are content-detected,
   dimension-bounded, decoded, and re-encoded before public placement.

No Phase 5 action queues a campaign, starts a worker, calls a live provider, or
creates a public recipient endpoint.

## Phase 6–7 delivery and unsubscribe operations

1. Apply `Version20260822000000` and `Version20260823000000` through the normal
   migration gate before starting the corresponding worker code.
2. Run the supervised worker and one scheduler invocation per minute as shown
   in [worker.md](worker.md). The scheduler also removes at most 100 expired
   recipient-token rows per run after their 30-day grace; suppression is never
   removed by this maintenance.
3. Every production recipient message gets a manual HTTPS unsubscribe URL in
   its body and `List-Unsubscribe` header. Public requests need no login or
   cookie and never reveal the address.
4. Leave the RFC 8058 provider-signing checkbox off unless the Owner has
   verified that the selected provider applies valid DKIM covering both
   unsubscribe headers. Enabling it requires recent re-authentication and is
   snapshotted per campaign. Phase 8 Doctor evidence is still required before
   treating production sending as healthy.
5. Configure the reverse proxy/access logger to redact the opaque segment of
   `/r/<token>/unsubscribe`; application logs and rate-limit keys never retain
   the raw token. Preserve generic 404/503 behavior during investigation.
6. A forwarded link can suppress only its original frozen recipient. Replays
   are expected and idempotent. Never delete a suppression because a token
   expired, was forwarded, or was used repeatedly.

## Phase 8 operations

1. Apply through `Version20260824010000`. Configure absolute external
   `EMAILER_RECOVERY_KEY_FILE`, `EMAILER_RELEASE_PUBLIC_KEY_FILE`, and
   `EMAILER_RELEASES_DIR` paths. Keep recovery package, master key, backups, and
   signing private key in distinct protection domains.
2. Run `emailer:doctor:run --json`. Exit 2 means critical and blocks sending;
   exit 1 means warning; exit 0 means healthy. `/admin/doctor` and its export
   contain stable codes, summaries, remediation, counts, opaque IDs, and times.
3. Export the recovery package with `emailer:secrets:export-recovery-key`; do
   not record its passphrase. Create and verify backups with
   `emailer:backup:create` and `emailer:backup:verify`.
4. For restore, quiesce delivery and allow the scheduler to recover interrupted
   claims until the reported count reaches zero. Then stop worker/scheduler,
   run interactive `emailer:backup:restore`, verify suppression and Doctor, and
   explicitly resume. Restore never resumes delivery itself.
5. Run `emailer:retention:run` for dry-run and add `--apply` only after review.
   Each category is capped at 200 rows; rerun for further bounded batches.
6. Inspect packages before change. For a `1.0.0` update, retain the exact signed
   predecessor archive and its four sidecars beside the target and pass it with
   `--source-package`; a source version string alone is not evidence. The target
   updater verifies both pinned signatures, checksums, manifests, SBOMs, the
   installed predecessor file set, compatibility, and disk space before mutation. It
   quiesces, performs an interrupted-claim recovery pass, creates a verified
   backup, installs immutably, migrates, switches atomically, and runs Doctor.
   Failure remains in maintenance and uses only compatible pointer rollback or
   verified restore.
7. After `emailer:release:update` succeeds, delivery is still quiesced. Restart
   `emailer-worker.service` and `emailer-scheduler.service` so every process
   loads the new immutable release, rerun Doctor, and only then run
   `emailer:delivery:resume`. Never resume by updating `delivery_runtime`
   directly.

## Phase 9 candidate operations

1. Provision the signing private key outside the repository/build/client host
   and the pinned public key through the custody and fingerprint ceremony in
   [release-signing.md](release-signing.md).
2. From a clean committed Phase 9 branch, run `composer check`, then invoke
   `tools/release/build-0.1.0-candidate.sh` with the private key, independently
   supplied public key, and an empty private output directory.
3. Retain the emitted candidate record, archive, deterministic manifest,
   checksum, SPDX SBOM, signature and public-key transfer copy together. Verify
   fingerprints through the independent channel before client installation.
4. Rehearse the documented clean install and inspect/Doctor/backup/restore
   workflow on a disposable server profile. Do not use production SMTP or DNS.
5. Stop after acceptance evidence. Publication, tag, deployment, client change,
   and production provider testing require separate authorization.
