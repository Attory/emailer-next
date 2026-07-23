# ADR 0008: Server-rendered Twig and AssetMapper UI

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

Version 1 needs an accessible administrative interface for forms, tables,
progress, and configuration. A separate SPA/API would enlarge the public
surface, authentication/token storage choices, build chain, and production
runtime requirements.

## Decision

Use server-rendered Twig templates, Symfony forms/security/CSRF, AssetMapper,
and small progressive Stimulus controllers. Production requires no Node.js and
there is no public campaign-creation API or SPA in version 1.

Business rules and authorization remain server-side. JavaScript may improve
interaction but cannot be required to preserve a security invariant or durable
campaign state.

## Consequences

- HTTP responses and forms are the main presentation contract.
- Progressive enhancement and browser accessibility are review requirements.
- Polling or small server-driven updates may present durable progress; browser
  counters are never authoritative.
- Asset dependencies remain minimal and pinned.

## Security and privacy impact

The smaller client surface avoids bearer-token storage and reduces dependency
risk. Twig escaping, explicit safe HTML handling, CSP, CSRF, cache control, and
server-side authorization are still mandatory.

## Alternatives considered

- A SPA was rejected as unnecessary for version 1 and operationally heavier.
- A Node.js production asset server was rejected.
- Unstructured server-rendered PHP was rejected in favor of framework routing,
  templates, forms, and security controls.

## Validation

Functional/browser tests run with and without progressive JavaScript where
appropriate, assert CSRF/authorization/security headers, and cover keyboard,
focus, labels, errors, tables, and non-colour status indicators.
