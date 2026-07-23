# Phase 1 completion report

**Date:** 2026-07-23

**Scope:** Reproducible Symfony platform scaffold only

**Result:** Repository implementation complete, subject to review of this
uncommitted diff and the first authorized Docker/CI run

Phase 1 adds the framework/runtime baseline, local services, quality gates,
coarse liveness, redaction, mail-test isolation, and artifact inspection. It
does not add setup, authentication, contacts, templates, campaigns, a delivery
worker, production sending, release, or deployment.

## Repository review and provenance

The phase began from the three-commit approved documentation history:

```text
c695ba0 phase 0: implement accepted Codex work (#1)
7e73707 docs: use canonical emailer-next repository name
7a66dc4 docs: establish Emailer Next clean-room specification
```

- `git rev-list --all --count` returned `3`.
- `git rev-list --max-parents=0 --all` returned the single approved root
  `7a66dc4aa5112e25ae35cf753fef182a991f29c6`.
- `main` and the isolated work branch began at the same tip. There were no
  tags, submodules, extra roots, or unreachable objects.
- The worktree exposed an `origin` pointing to a local path outside
  `/workspace`. Its target was not accessed. The remote was removed; `git
  remote` then returned no names.
- Pre-change current-tree/history secret, private-key, recipient-like data,
  prohibited-artifact, ref, and `git fsck --full --strict --no-reflogs
  --unreachable` checks passed.
- No credential file was opened. Symfony Flex-created `.env`, `.env.dev`, and
  `.env.test` files were deleted without reading; only the placeholder
  `.env.example` contract remains.

Generated Composer dependencies come from the reviewed lockfile and are ignored
working state, not legacy vendored source. All authored implementation derives
from the canonical specifications, accepted ADRs, official upstream
documentation, and current framework recipes.

## Implemented scope

- Symfony 7.4 LTS project for PHP `>=8.4 <8.6` with `composer.lock` and
  `symfony.lock`.
- Doctrine DBAL/ORM, MariaDB driver, Migrations, Security, Mailer, Messenger,
  Twig, AssetMapper/Stimulus, Validator, RateLimiter, Lock, Monolog, PHPUnit,
  PHPStan, Deptrac, PHP-CS-Fixer, and scan/build tooling.
- Dockerfile and Compose services for PHP, MariaDB, and Mailpit. Application and
  Mailpit ports bind to loopback; MariaDB has no host port.
- `./bin/bootstrap` for image build, locked install, service startup, aggregate
  checks, application startup, and exact liveness verification.
- GitHub Actions matrix for PHP 8.4.23/8.5.8 and MariaDB
  10.11.18/11.8.8, with full-history checkout and no persisted checkout
  credential.
- Structured JSON logging with central recursive redaction.
- Test-kernel Mailpit-only DSN validation plus a CI internal-network negative
  egress check.
- Explicit runtime artifact allowlist, production-only dependency install,
  content inspection, and loopback public-path probes.
- `GET|HEAD /health/live` with fixed coarse JSON, no-store policy, and no
  session.

Messenger has transports but no routed application message. Doctrine has no
business entity. Security has no account or UI. There is no arbitrary-send
command or route.

## Dependency and compatibility decisions

Official sources reviewed on 2026-07-23 support the Phase 1 decisions recorded
in ADRs 0002, 0003, and 0010:

- PHP 8.4 and 8.5 are supported branches; the matrix pins 8.4.23 and 8.5.8.
- Symfony 7.4 is the maintained LTS; the lockfile resolves core framework
  7.4.14.
- MariaDB 10.11 and 11.8 are maintained long-term branches; the matrix pins
  10.11.18 and 11.8.8.
- Mailpit is pinned to 1.30.5.
- Composer is pinned to 2.10.2 and Gitleaks to checksum-verified 8.30.1.

References:

- https://www.php.net/supported-versions.php
- https://symfony.com/releases/7.4
- https://mariadb.org/about/#maintenance-policy
- https://mariadb.com/resources/blog/mariadb-community-server-q2-2026-corrective-releases/
- https://github.com/axllent/mailpit/releases/tag/v1.30.5

## Executed checks

No Docker executable is installed on this host, so the Dockerfile,
`./bin/bootstrap`, Compose topology, PHP 8.5/MariaDB 11.8 matrix entries, and
GitHub-hosted workflow could not be executed here. No remote was present and the
job forbids pushing, so remote CI was not triggered. The same application gates
were run directly inside `/workspace` with official checksum-verified temporary
PHP 8.4.23, Composer 2.10.2, Gitleaks 8.30.1, MariaDB 10.11.18, and Mailpit
1.30.5 binaries; temporary tooling is not retained.

| Check | Result |
| --- | --- |
| Locked dependency install | Pass; Composer installed the lockfile on PHP 8.4.23 with required extensions |
| Pinned container tag lookup | Pass for PHP 8.4.23/8.5.8, Composer 2.10.2, MariaDB 10.11.18/11.8.8, and Mailpit 1.30.5 |
| `composer validate --strict` | Pass |
| `composer audit --locked --abandoned=fail` | Pass; no advisory or abandoned-package failure |
| PHP-CS-Fixer check | Pass; no fixable file |
| PHPStan | Pass at maximum level; no error/baseline |
| Deptrac | Pass; zero violations and zero errors |
| Project module-boundary check | Pass |
| Shell syntax check | Pass |
| Framework boot | Pass in non-debug test environment |
| Liveness HTTP tests | Pass for exact GET body/headers/no session and empty HEAD body |
| MariaDB connection | Pass against MariaDB 10.11.18 on loopback |
| Migration smoke | Pass; one migration executed, then up-to-date; mappings valid |
| Mailpit transport | Pass; one Symfony Mailer message with reserved synthetic addresses accepted |
| Mailpit capture verification | Pass; API reported exactly one stored synthetic message |
| PHPUnit | Pass; 17 tests and 41 assertions in one run against MariaDB/Mailpit |
| Artifact locked production install/platform requirements | Pass |
| Artifact exact allowlist/development-package/content inspection | Pass |
| Artifact liveness and forbidden-path probe | Pass |
| Current-directory Gitleaks scan | Pass; includes ignored/untracked dependencies, artifact, and generated logs |
| Full reachable-history Gitleaks scan | Pass |
| Private-key/prohibited-extension/private-data scans | Pass for current project files, history, generated logs, and artifact |
| Git integrity/unreachable-object check | Pass; no output |
| Whitespace/error-marker checks | Pass |

The Mailpit test used only `phase1-sender@example.test` and
`phase1-recipient@example.test`. No live SMTP socket was opened. Unit tests
reject absent, non-SMTP, non-local, wrong-port, credentialed, option-bearing,
and suffix-confusion DSNs before kernel boot. The CI workflow additionally uses
a Docker `--internal` network and requires a socket to `192.0.2.1:25` to fail
before repeating PHPUnit.

## Clean-room checklist disposition

**Reviewer:** Codex, with final operator review still required for the
uncommitted diff.

### Repository origin

- **Pass:** the reachable graph has one approved specification root and three
  documentation-only commits.
- **Pass:** no legacy or temporary remote remains; the discovered local
  `origin` was removed without accessing its target.
- **Pass:** no legacy commit, tag, extra branch history, submodule, subtree,
  patch, archive, or legacy vendor bundle is present.
- **Pass for authored work:** no legacy file was inspected or copied.
  Off-repository human provenance remains an operator attestation that machine
  checks cannot prove.

### Secrets and private data

- **Pass:** no real SMTP credential, administrator password, API token, private
  key, recovery code, production environment, recipient/list, operational
  log/queue/backup/export, or private template was used or found.
- **Pass:** current tree, full reachable history, build artifact, synthetic
  tests, and generated logs passed secret/private-data checks.
- **Pass:** `.env.example`, Compose, CI, PHPUnit, and documentation use only
  empty or obvious local/test placeholders. Dependency-author addresses in
  Composer lock metadata are public upstream metadata, not application
  recipient fixtures; lockfiles remain covered by secret scanning.

### Network safety

- **Pass:** the executed mail integration used a loopback-only Mailpit process
  and reserved synthetic data.
- **Pass at application level:** test kernel startup rejects every destination
  except Mailpit/loopback port 1025 without credentials or options.
- **Pass by workflow inspection; execution pending Docker:** CI constructs an
  internal network and checks external egress failure before the isolated test
  repeat.
- **Pass:** no production webhook, DNS/account action, deployment, or production
  service contact occurred.

### Architecture

- **Pass:** one-installation/one-organisation and no-tenant rules are enforced
  by static checks; no business schema exists.
- **Pass:** the artifact/web probe serves only `public/`.
- **Not applicable:** there is no background sender, state-changing admin
  action, or public recipient endpoint. The CLI-only/auth/authorization/CSRF
  and purpose-token rules remain locked for their owning phases.
- **Not applicable:** no dynamic application secret is stored yet; the
  encrypted-record design remains locked for Phase 3.
- **Pass:** structured logs use the redaction processor and redaction tests.

### Review

- **Pass:** implementation, tests, migration, artifact policy, and generated
  output were inspected.
- **Pass:** architecture, data/route/threat/security models, ADRs, quality,
  development/testing/installation/operator/release/upgrade documentation, and
  open decisions were updated.
- **Pass:** migration and manual actions are identified below.
- **Pass:** no Phase 2 or later implementation was started.

## Migration

`DoctrineMigrations\Version20260723000000` is the only migration.

- `up()`: `SELECT 1`
- `down()`: `SELECT 1`
- Business tables created: none
- Automatic metadata: Doctrine creates/updates its migration-version table when
  the migration is applied
- Data migration or rollback risk: none

This is a platform wiring smoke check only. It is not authorization to migrate a
production system.

## Manual actions and limitations

- **Required now:** none. Nothing was committed, pushed, merged, released, or
  deployed.
- **Optional local verification:** install Docker with the Compose plugin and
  run `./bin/bootstrap`; use synthetic local data only.
- **Before accepting Phase 1 on a shared branch:** run the checked-in CI matrix
  on an authorized clean destination. Docker-based results are the sole
  environment limitation in this report.
- **Remote:** no remote remains. Adding an operator-approved clean destination
  and pushing are separate future actions.
- **Configuration:** do not create a production `.env` or add real database,
  mail, recipient, key, or private-storage values to this repository.
- **Incomplete by design:** ownership proof/setup, secret encryption, identity,
  contacts/lists/suppression, templates, campaigns, worker/delivery, recipient
  actions, Doctor, backup/restore/retention, representative production web
  server profiles, SBOM/checksum, and release acceptance all remain in Phases
  2–9.

Stop after Phase 1. Do not begin another phase from this report.
