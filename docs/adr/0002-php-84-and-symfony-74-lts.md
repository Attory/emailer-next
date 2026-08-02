# ADR 0002: PHP 8.4+ and Symfony 7.4 LTS

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phases:** 0–1

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

Phase 1 resolves the initial matrix to PHP 8.4.23 and 8.5.8. Composer requires
`>=8.4 <8.6`, and CI runs the aggregate platform gate on both branches. Symfony
components remain constrained to `7.4.*`; the Phase 1 lockfile resolves the core
framework to 7.4.14.

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
audit/static analysis/tests, and rejects unsupported versions. The initial
Phase 1 matrix covers PHP 8.4.23 and 8.5.8. Configuration Doctor will report
runtime/framework support and patch status when implemented.

References:

- https://www.php.net/supported-versions.php
- https://symfony.com/releases/7.4
- https://symfony.com/doc/7.4/
