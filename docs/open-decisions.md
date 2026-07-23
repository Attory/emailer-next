# Open decisions

**Status:** Phase 0 handoff

**Last reviewed:** 2026-07-23

The core architecture in ADRs `0001`–`0009` is accepted. The items below are
deliberately deferred to the phase with enough implementation evidence. None
authorizes a weaker security outcome.

| ID | Decision | Must be resolved by | Required evidence / constraint |
| --- | --- | --- | --- |
| OD-01 | Phase 1 PHP CI matrix: minimum 8.4 plus which newer supported branches | Phase 1 | Official PHP support table; Symfony 7.4 compatibility; same aggregate checks on each supported branch |
| OD-02 | MariaDB compatibility matrix above the 10.11 minimum | Phase 1 | Test 10.11 and a selected newer LTS; Doctrine/migration behavior; documented support window |
| OD-03 | Coding-standard/formatter and architecture-rule tooling | Phase 1 | Maintained tools, deterministic Composer install, no unreviewed baseline |
| OD-04 | Exact local container topology and CI network denial used to make live SMTP unreachable | Phase 1 | Negative egress test; Mailpit-only default; no production hostname or credential |
| OD-05 | Canonical account login identifier and normalization rules | Phase 3 | Individual accounts; generic recovery; conservative address handling; uniqueness behavior in MariaDB |
| OD-06 | Session idle/absolute limits, re-authentication window, TOTP parameters, and recovery-code count | Phase 4 | OWASP/current framework guidance, usability review, clock-boundary tests |
| OD-07 | Audit-sink failure behavior for each sensitive transaction class | Phase 4 | Fail-closed matrix that preserves data integrity and operator-visible recovery |
| OD-08 | Permission and approval policy for full-address display/export and suppression removal | Phase 5 | Least privilege, auditability, privacy/legal operator approval; suppression cannot be bypassed |
| OD-09 | HTML sanitizer and safe merge-language implementation | Phase 5 | Maintained dependencies, context-aware encoding, fuzz/XSS/header-injection tests |
| OD-10 | Delivery retry numbers, ambiguous-outcome workflow, circuit-breaker thresholds, and global/per-domain defaults | Phase 6 | Fake-provider fault tests; at-least-once wording; bounded operator-approved limits |
| OD-11 | Public token representation, expiry/revocation, forwarding, and key-overlap policies | Phase 7 | Wrong-purpose/tamper/replay tests; no raw address; privacy explanation |
| OD-12 | Tracking/RSVP policy defaults beyond “off,” and concrete data-retention periods | Phase 7/8 | Operator legal/privacy decision, minimization, dry-run retention tests |
| OD-13 | Backup envelope/manifest format, passphrase KDF parameters, and optional external destination | Phase 8 | Independent recovery review, corruption/wrong-passphrase tests, key excluded |
| OD-14 | License and supported-browser/accessibility matrix | Before public release | Dependency/license audit and operator product decision; WCAG-oriented acceptance evidence |

The operator must later supply deployment-specific values—base URL, proxy
topology, filesystem paths, resource limits, sender identity, SMTP/provider
configuration, retention periods, backup destination, and DNS records—outside
Git and prompts. Those are installation decisions, not reasons to collect real
values during development.

No open decision permits multi-tenancy, a non-`public/` document root, plaintext
dynamic secrets, TLS-verification bypass, HTTP delivery, suppression bypass,
live SMTP in tests, or exactly-once delivery claims.
