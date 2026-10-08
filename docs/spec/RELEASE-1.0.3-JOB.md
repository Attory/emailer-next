# Release 1.0.3 — User confirmation repair and campaign manual

Anton reported the user-creation 403 after testing deployed 1.0.2 and requested
its correction plus a basic campaign creation/editing manual on 2026-10-09.
This authorizes the bounded repair from clean synchronized main
`de92b08227d83aa096613956d642e390f88166bc` (PR18 merged, deployed source
`4eca16bc7b212a8d35bfe1b6f05f199443a611df`).

## Scope

- Inline purpose-scoped password/MFA confirmation for existing Create, Update
  and Delete user actions; expiry returns an actionable form rather than 403.
- Preserve route/service authorization, CSRF, throttling, session rotation,
  recovery-code semantics, Owner invariants and private/no-store responses.
- Retain only non-secret creation fields after confirmation failure; never
  redisplay or persist an initial password, current password or MFA value.
- Basic authenticated printable campaign manual covering creation, editing,
  template versions, tests, approval, scheduling, rates, progress and troubleshooting.
- Patch packaging for exactly 1.0.2 → 1.0.3; same schema and no dependencies.

No sending integration, campaign behavior, schema, account permission or later
phase is introduced. No customer mail, live test, signing, publication or
production mutation is authorized by development. Prepare one draft PR, all
canonical gates and independent source review. Follow AGENTS.md exact-head
merge approval before operator signing/deployment of this new repair candidate.
