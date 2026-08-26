# Open decisions

**Status:** Decisions through Phase 12 resolved; Phase 13 acceptance decisions
remain bounded to that phase.

**Last reviewed:** 2026-08-26

The core architecture in ADRs `0001`–`0011` is accepted. Phase 1 resolved its
four bounded decisions:

| ID | Resolution |
| --- | --- |
| OD-01 | CI supports PHP 8.4 and 8.5; Phase 9 revalidation pins 8.4.24 and 8.5.9. |
| OD-02 | Phase 12 revalidation advances CI to MariaDB 10.11.19 and the newer long-term branch 11.8.9. |
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
| OD-08 | Resolved in Phase 5: ordinary contact/suppression views are masked; only Administrator or Owner may reveal/export full addresses after recent re-authentication, CSRF, and audit; Phase 5 exposes no suppression removal/bypass path | Phase 5 | Least privilege and add-only suppression are enforced at route and application layers |
| OD-09 | Resolved in Phase 5: maintained Symfony HTML Sanitizer with a strict custom allowlist; exact non-evaluating merge fields; URL fields only as complete approved link targets; context-aware render escaping and immutable content digests | Phase 5 | XSS, unsafe URL, merge-expression, attribute-context, header-injection, and synthetic-preview tests |
| OD-10 | Resolved in Phase 6: transient delivery retries use 60-second exponential backoff with deterministic 0–30-second jitter, capped at one hour and at the configured retry limit; ambiguous outcomes are terminal and never blindly retried; five consecutive transport failures open the circuit for five minutes; the global per-minute limit is configured and each recipient domain receives at most half that limit per window. | Phase 6 | Fake-provider fault tests; at-least-once wording; bounded operator-approved limits |
| OD-11 | Resolved in Phase 7: `vN.random-handle.HMAC` bearer token; 365-day expiry and key-verification overlap; revoked/expired fail generically; rows purged 30 days after expiry; forwarding can only suppress the frozen recipient; replay is idempotent | Phase 7 | Wrong-purpose/tamper/version/expiry/replay/rotation tests; no raw address in URL, logs, rate keys, or response |
| OD-12 | **Resolved Phase 12:** policy UI enforces bounded per-class periods; analytics/RSVP remain independently 1–365 days, operational classes have narrower safe ranges, durable audit/suppression/campaign evidence is excluded, and each run is dry-run capable and capped at 200 rows per category | Phases 10–12 | Deployment-specific periods and legal holds remain operator decisions |
| OD-13 | **Resolved Phase 12:** the first approved external adapter is an operator-provisioned absolute filesystem mount outside release/private roots. Automation is off by default; exclusive mode-0600 copy, byte count, SHA-256 read-back, redacted evidence, and bounded deletion are mandatory | Phase 8 / Phase 12 | Cloud/provider adapters require a new reviewed threat model and authorization |
| OD-14 | License and supported-browser/accessibility matrix | Phase 13 | Dependency/license audit and operator product decision; WCAG-oriented acceptance evidence |
| OD-15 | **Resolved Phase 9:** operator-controlled offline Ed25519 private key with separate encrypted recovery custody; independently fingerprint-verified external public-key pin; explicit old-key bridge and out-of-band ceremony for rotation; first release is clean-installed while the acceptance-only path rehearses `0.1.0-rc.1` to `0.1.0`; later minors support the immediately preceding release line as documented | Phase 9 | See [release-signing.md](release-signing.md); supported, tampered, wrongly signed, same/downgrade, and unsupported-source rehearsals remain blocking candidate evidence |

The operator must later supply deployment-specific values—base URL, proxy
topology, filesystem paths, resource limits, sender identity, SMTP/provider
configuration, retention periods, backup destination, and DNS records—outside
Git and prompts. Those are installation decisions, not reasons to collect real
values during development.

No open decision permits multi-tenancy, a non-`public/` document root, plaintext
dynamic secrets, TLS-verification bypass, HTTP delivery, suppression bypass,
live SMTP in tests, or exactly-once delivery claims.
