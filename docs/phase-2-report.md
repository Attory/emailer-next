# Phase 2 completion report

**Date:** 2026-08-15

**Phase/job:** Phase 2 — Filesystem ownership proof and setup state machine

**Branch:** `codex/phase-2-filesystem-setup-state`

**Base:** `origin/main` at
`b6c38b246dae19a6aaf683e3f4c0b6660ee27c2e`

**Result:** Phase 2 implementation and local validation complete; draft pull
request review and its terminal GitHub CI result remain the merge gate.

## Starting state and canonical inputs

The phase began by fetching `origin` with pruning and verifying that local
`main`, `origin/main`, and `HEAD` were clean and synchronized at the exact Phase
1 milestone `b6c38b246dae19a6aaf683e3f4c0b6660ee27c2e`. Phase 0 milestone
`c695ba04e8de800e5dde5019ae37f0c59a5d1d1d` was verified as an ancestor.

Every required canonical document existed and was read before product
implementation:

- `docs/spec/EMAILER-NEXT-MASTER-SPEC.md`;
- `docs/spec/PHASE-JOBS.md`;
- `docs/spec/CLEAN-ROOM-CHECKLIST.md`;
- `docs/phase-1-report.md`;
- relevant ADRs 0001, 0003, 0004, 0005, and 0010;
- architecture, module, route, data, threat, security, setup, installation,
  development, testing, quality, operator, and open-decision documentation.

The repository lacked a root `AGENTS.md`. Anton separately authorized a
one-time bootstrap, and the supplied content was created verbatim as the first
working-tree change on this Phase 2 branch, then read and followed.

The fetch also revealed `origin/emailer-mitek`, an unrelated branch not merged
into `main`. Anton explicitly waived its presence as an authorized unrelated
branch and directed Phase 2 to proceed. No file or commit from that branch was
inspected, copied, merged, or used as implementation input. Unreachable local
Git objects reported by the preflight were likewise not used.

## Implemented scope

- A 160-bit random ownership code encoded as 32 uppercase Crockford Base32
  characters and displayed in eight four-character groups.
- A separate opaque challenge identifier and mode-`0600` private JSON state
  containing only code/IP hashes, timestamps, and failed-attempt metadata.
- Fifteen-minute expiry, ten-attempt rotation, per-client challenge/proof rate
  limits, and a shared filesystem lock around issuance and verification.
- Fixed `.emailer-install-proof` verification with no HTTP code input. Reads
  reject links, non-regular files, sizes over 256 bytes, group/world-write
  permission, inode replacement, or unsafe post-read metadata.
- Generic public proof failures; success deletes proof/state and rotates the
  setup session identifier.
- Fail-closed setup-mode routing, coarse liveness exception, post-lock empty
  404 setup responses, and post-proof HTTPS gating.
- A credential-free preflight for supported PHP, required extensions, exact
  `public/` document root, restrictive private storage, public/private
  separation, PDO MySQL, and CLI capability.
- Script-free setup templates that do not require downloaded import-map assets.
- An atomic, restrictive installation-lock abstraction and a credential-free
  CLI ownership marker.
- `emailer:setup:install`, bounded to ownership/preflight and intentionally
  unable to complete installation.
- `emailer:setup:reset`, requiring a recent restrictive backup outside
  `public/`, production-data detection, an explicit destructive flag where
  applicable, and interactive confirmation. It reads or exports no secret.

## Dependencies, schema, and routes

- Composer dependencies changed: none.
- Composer lockfile changed: no.
- Migrations added or changed: none.
- Business tables or recipient data added: none.
- Implemented HTTP routes: `GET /setup`, `POST /setup/proof/verify`, and
  `GET /setup/preflight` only.
- Implemented CLI commands: `emailer:setup:install` and
  `emailer:setup:reset` only.

The pre-existing Phase 1 migration remains the only migration. Phase 2 does not
collect a database password, generate a master key, create an organisation or
Owner, configure SMTP, send mail, or implement a worker.

## Executed local validation

Commands were run from the repository root with the pinned Docker Compose
toolchain:

```text
docker compose run --rm app composer exec phpunit -- --display-all-issues tests/Module/Setup tests/Platform/SetupFlowTest.php
docker compose run --rm app composer check:static
./bin/bootstrap
docker compose -f compose.yaml -f /tmp/emailer-next-phase2-port-override.yaml up --detach --wait app
curl --fail --silent --show-error http://127.0.0.1:18080/health/live
git diff --check
```

Results:

| Check | Result |
| --- | --- |
| Focused Phase 2 PHPUnit | Pass: 21 tests, 127 assertions, no issue |
| Aggregate PHPUnit | Pass: 38 tests, 168 assertions |
| Composer validation/audit | Pass; no advisory or abandoned-package failure |
| PHP-CS-Fixer | Pass; zero fixable files |
| PHPStan | Pass; no error or baseline |
| Deptrac/module boundaries | Pass; zero violation/error |
| Shell checks | Pass |
| MariaDB migration/mapping | Pass; one existing migration, then up to date; mappings valid |
| Fake-mail isolation | Pass through aggregate suite; no live SMTP used |
| Secret/private-data scans | Pass; current tree and reachable history |
| Production artifact/public probes | Pass |
| Exact liveness | Pass: `{"status":"ok"}` on isolated loopback port 18080 |
| Whitespace | Pass: `git diff --check` produced no output |

The repository bootstrap's aggregate `composer check` completed successfully.
Its final default-port app start could not bind because an unrelated existing
host service already owned port 8080. No service was stopped or restarted.
The already-built application was instead started with a temporary Compose
override on `127.0.0.1:18080`; all three Compose services became healthy and
the exact liveness check passed. The override is outside the repository and is
not an application configuration change.

## Security and privacy review

- All proof and setup files use generated synthetic values and restrictive
  modes; no real credential, recipient, production hostname, or private record
  was introduced.
- The raw challenge exists only in the instruction response/proof file. Private
  state stores its SHA-256 hash, and logging receives only challenge ID/result.
- Verification is serialized and single-use; replay sees no state. Filesystem
  validation compares path/opened/post-read metadata to reject replacement.
- HTTP input contains only CSRF/session authority, never the challenge code.
- Sensitive wizard fields are absent. HTTPS is enforced before any later phase
  could add one.
- Reset is CLI-only, backup/data guarded, interactive, and does not print or
  export existing secrets.
- Setup routes are permanently hidden by an installation lock, while ordinary
  resolved routes fail closed before completion.
- No production service, DNS, account, SMTP target, deployment, or recipient
  was contacted.

## Manual actions and unresolved risks

- GitHub CI must be terminal and passing on the exact draft PR head before
  merge approval.
- The host's unrelated port-8080 listener remains unchanged. Operators running
  the default local app must either free that port intentionally or use an
  explicit reviewed loopback override.
- Phase 2 provides the lock abstraction but deliberately does not create the
  final completed-installation lock through web/CLI setup; Phase 3 owns that
  irreversible transition after secrets, identity, migrations, and Doctor.
- Backup cryptographic creation/verification remains Phase 8. Phase 2 reset can
  validate only the available file type, path, mode, and recency boundary.
- The unrelated waived `origin/emailer-mitek` branch remains outside this
  phase's provenance and review scope.

## Phase-boundary evidence

No Phase 3 or later behavior was started. There is no new migration, entity,
credential form, secret envelope, master key, Owner/account, organisation,
mail transport configuration, SMTP test, recipient/contact/template/campaign,
queue message, delivery worker, public recipient action, Doctor, backup engine,
release, or deployment implementation.

Stop after Phase 2. Anton's next required intervention is approval of the exact
draft Phase 2 pull request and full current head SHA; this report does not
authorize merge or Phase 3.
