# ADR 0002: PHP 8.4+ and Symfony 7.4 LTS

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

The application needs a maintained runtime, a security-focused framework, and a
predictable support horizon. Long-term support reduces avoidable framework
upgrades during version 1 while supported newer PHP branches permit orderly
runtime upgrades.

Official support pages reviewed on 2026-07-23 list PHP 8.4 and 8.5 as supported
and Symfony 7.4 as the maintained LTS branch. Symfony 7.4 requires PHP 8.2 or
newer; Emailer Next intentionally sets the higher PHP 8.4 minimum.

## Decision

Require PHP 8.4 or a newer branch that is both officially supported and verified
by the project test matrix. Use Symfony 7.4 LTS and pin packages to a supported
patch through `composer.lock`. Do not float to Symfony's latest feature branch
or unbounded dependency versions.

Phase 1 selects the exact supported PHP matrix and Symfony patch after repeating
the official support check.

## Consequences

- Hosts must provide PHP 8.4+ and the extensions in the master specification.
- Composer and CI enforce platform requirements and locked installs.
- New PHP branches are not claimed as supported until the full gate passes.
- Symfony security/bugfix updates require reviewed lockfile updates.

## Security and privacy impact

Supported branches receive upstream fixes. A lockfile improves reproducibility
but requires dependency auditing and timely patch review. Debug/profiler
packages and modes remain absent from production artifacts/configuration.

## Alternatives considered

- PHP 8.2/8.3 were rejected as below the approved product baseline.
- Symfony 8.x was rejected for version 1 because the approved LTS support
  horizon and compatibility baseline are Symfony 7.4.
- Independently selected Symfony components without the framework were rejected
  because they would increase security integration work for sessions, CSRF,
  authorization, and configuration.

## Validation

CI tests every declared PHP branch, validates the lockfile, runs dependency
audit/static analysis/tests, and rejects unsupported versions. Configuration
Doctor reports runtime/framework support and patch status.

References:

- https://www.php.net/supported-versions.php
- https://symfony.com/releases/7.4
- https://symfony.com/doc/7.4/
