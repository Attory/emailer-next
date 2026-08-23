# ADR 0010: Phase 1 toolchain and test isolation

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 1

## Context

Phase 1 had to resolve the supported runtime/database matrix, deterministic
quality tools, local service topology, and an enforceable guarantee that tests
cannot contact live SMTP. These choices must work from a fresh checkout and
must not introduce a second policy that differs between local development and
CI.

Official support/release information reviewed on 2026-07-23 showed PHP 8.4 and
8.5 as supported, Symfony 7.4 as the maintained LTS, MariaDB 10.11 and 11.8 as
maintained long-term branches, and Mailpit 1.30.5 as the current release.

## Decision

Use this initial CI matrix:

- PHP 8.4.23 with MariaDB 10.11.18;
- PHP 8.4.23 with MariaDB 11.8.8;
- PHP 8.5.8 with MariaDB 10.11.18.

Phase 9 revalidation on 2026-08-23 updates the matrix patches to PHP 8.4.24
with both MariaDB lines and PHP 8.5.9 with MariaDB 10.11.18. Composer 2.10.2,
Mailpit 1.30.5, Gitleaks 8.30.1, MariaDB 10.11.18/11.8.8, and the isolation
model remain current and unchanged.

Use Composer 2.10.2 with a committed lockfile. Use PHP-CS-Fixer for style,
PHPStan at maximum level with strict/Symfony/Doctrine extensions, Deptrac plus a
project-owned PHP boundary check for architecture, PHPUnit for executable
tests, and checksum-pinned Gitleaks 8.30.1 for current/full-history secret
scanning. No PHPStan baseline, ignored static error, or directory-wide scanner
exception is accepted.

Docker Compose contains only the PHP application, MariaDB, and Mailpit on an
internal development network. Application and Mailpit UI host bindings use
loopback; MariaDB has no host port. Default values are obvious synthetic
local-only placeholders.

Before a test kernel boots, `MAILER_DSN` must be plain `smtp` to `mailpit`,
`127.0.0.1`, or IPv6 loopback on port 1025, without user information, query,
fragment, or path. CI repeats PHPUnit with only MariaDB and Mailpit attached to
a Docker `--internal` network and first proves a socket to the reserved
TEST-NET address `192.0.2.1:25` cannot open.

Build a production-shaped directory from an explicit runtime allowlist, install
locked production dependencies only, scan it, inspect it, and probe it through
the `public/` document root. This is test output, not a release.

## Consequences

- A fresh local bootstrap needs Docker/Compose and network access to reviewed
  dependency/container sources.
- Supported-version claims are bounded by the matrix; new branches require an
  explicit reviewed update.
- Local tests use MariaDB and Mailpit rather than SQLite or a real provider.
- The application policy and CI network policy are independent controls against
  unintended SMTP.
- Static and scanner false positives require line-level review instead of a
  blanket baseline.
- Representative production web-server profiles, SBOM/checksum generation, and
  release packaging remain later release-hardening work.

## Alternatives considered

- Supporting only PHP 8.4 was rejected because the currently supported newer
  branch can be tested cheaply and catches forward-compatibility defects.
- Treating every MariaDB version above 10.11 as implicitly compatible was
  rejected; 11.8 is tested explicitly.
- SQLite integration tests were rejected by ADR 0003.
- An application-only Mailpit setting was rejected because a configuration
  regression could otherwise regain network reachability.
- A blanket `vendor/` scanner exclusion was rejected because the locked
  dependency tree and production artifact are part of the supply-chain
  boundary.

## Validation

`./bin/bootstrap` performs the clean install and aggregate check. CI builds the
same Dockerfile for every matrix entry, runs `composer check`, repeats PHPUnit
on the internal network, and runs `git diff --check`. Phase results record exact
tool versions, scan outcomes, migrations, and unavailable external actions.

References:

- https://www.php.net/supported-versions.php
- https://symfony.com/releases/7.4
- https://mariadb.org/about/#maintenance-policy
- https://github.com/axllent/mailpit/releases/tag/v1.30.5
