# Open decisions

**Status:** Phase 1 handoff

**Last reviewed:** 2026-07-23

The core architecture in ADRs `0001`–`0010` is accepted. Phase 1 resolved its
four bounded decisions:

| ID | Resolution |
| --- | --- |
| OD-01 | CI supports PHP 8.4 and 8.5, with patches 8.4.23 and 8.5.8 pinned at Phase 1 completion. |
| OD-02 | CI tests MariaDB 10.11.18 and the newer long-term branch 11.8.8. |
| OD-03 | PHP-CS-Fixer, maximum-level PHPStan, Deptrac, and a project boundary checker are the baseline; no static-analysis baseline or ignored error was added. |
| OD-04 | Compose uses one internal application/database/Mailpit network, loopback host bindings only, no database host port, a strict test-kernel Mailpit DSN allowlist, and a CI-only internal network with an external-egress negative probe. |

The implementation rationale and consequences are in
[ADR 0010](adr/0010-phase-1-toolchain-and-test-isolation.md).

The remaining items below are deliberately deferred to the phase with enough
implementation evidence. None authorizes a weaker security outcome.

| ID | Decision | Must be resolved by | Required evidence / constraint |
| --- | --- | --- | --- |
| OD-05 | Resolved in Phase 3: initial account login is an individual email, Unicode NFC-normalized, trimmed, and case-folded for a unique MariaDB comparison value | Phase 3 | The entered Phase 3 value is not reused as recipient normalization policy; later recovery responses remain generic |
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
