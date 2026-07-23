# Testing

**Status:** Phase 0 scan baseline; executable application tests begin in Phase 1.

The stable gate order and exact Phase 0 scan recipes are in
[quality-gates.md](quality-gates.md). Phase results are recorded in a phase
report without matched secret/private content.

## Test-data policy

- Use generated synthetic data and reserved domains only.
- Never use a real recipient, message, credential, production token, private
  template, backup, export, log, or screenshot.
- Test secrets are obvious non-production placeholders and remain confined to
  a network-isolated test environment; snapshots/logs must not reproduce them.
- Development and CI can contact Mailpit only. A negative test and CI network
  policy must prove other SMTP destinations are unreachable.
- MariaDB, not SQLite, is used for integration/concurrency equivalence.
- Clean temporary private files after success and failure, then scan generated
  outputs.

## Test layers

- Unit tests for domain transitions, value objects, tokens, crypto envelopes,
  parsing, redaction, and policy.
- MariaDB/Mailpit integration tests for persistence, migrations, mail, queue,
  worker concurrency, and backup/restore.
- Functional HTTP tests for route authorization, CSRF, caching, headers,
  setup/public/admin isolation, and generic errors.
- Browser accessibility/workflow tests for setup, identity, imports, campaigns,
  and Doctor.
- Property/fuzz tests for CSV, merge/token parsing, URLs, and malformed input.
- Deployment tests for Nginx/Apache public exposure and immutable artifact
  allowlist.

## Mandatory safety properties

Every release must prove all fifteen properties in master specification section
22.2, including: no send before setup; no unauthenticated privileged mutation;
CSRF rejection; wrong-purpose token safety; suppression on every send path;
live-SMTP impossibility; output redaction; safe proof-file validation; permanent
setup lock; durable worker recovery/progress; no raw address in public URLs;
private artifact boundary; suppression-preserving restore; and fail-closed key
loss.

Each property maps to named tests and at least one negative case. “Covered by
framework” without a repository test is insufficient for route/role/CSRF,
session, proxy, or serialization behavior.

## Phase 0 evidence

Phase 0 runs no PHP tests because no application exists. Its required checks
are:

- current-tree high-confidence secret scan;
- full reachable-history high-confidence secret scan;
- current/history private-key header scan;
- current/history recipient-email and sensitive-fixture-extension scan;
- Git object integrity/unreachable-object check;
- `git diff --check`;
- manual review of provenance, refs, complete diff, documentation, migrations
  (none), and manual actions.

See [phase-0-report.md](phase-0-report.md) for the executed command outcomes and
scanner limitations.
