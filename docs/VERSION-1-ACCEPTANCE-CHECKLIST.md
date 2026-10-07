# Emailer Next 1.0.0 acceptance checklist

**Candidate status:** Phase 13 implementation; not signed, tagged, published,
deployed, or approved for client installation.

This checklist is release-blocking. `PASS` requires reproducible evidence bound
to the exact Phase 13 head. A changed head invalidates prior review, CI,
candidate, signing, and merge approval for the earlier SHA.

## 1. Source and scope

| Check | Status | Evidence |
| --- | --- | --- |
| Exact clean Phase 12 closeout base and synchronized `origin/main` | PASS | Base `39c8eeccea9866215ce231a08d0e32df9b341199`; PR #16 closeout |
| Phase 13 branch contains only Version-1 closure/acceptance work | PASS | Diff review and unchanged schema `20260828000000` |
| Walkthrough source repair introduces no live SMTP, provider, DNS, deployment, tag, or publication mutation | PASS | Fake Mailpit tests; existing separately authorized acceptance installation is unchanged |
| Complete route inventory reconciled; no new HTTP route | PASS | [route-inventory.md](route-inventory.md) and exhaustive policy tests |
| Full threat/security/architecture review updated | PASS | [threat-model.md](threat-model.md), [security-model.md](security-model.md), [architecture.md](architecture.md) |

## 2. Version-1 functional closure

| Check | Status | Evidence |
| --- | --- | --- |
| HTTPS-independent full CLI installation alternative | PASS | Hidden interactive input, canonical setup services, permanent-lock integration test |
| Setup secrets absent from arguments, output, and setup state | PASS | No secret-bearing options; output assertions and existing setup-state tests |
| Owner MFA path remains available without CLI disclosure | PASS | CLI skips enrollment and client manual requires immediate authenticated HTTPS enrollment |
| Production secret replacement remains authenticated and Owner-gated | PASS | Existing HTTPS mail configuration workflow; generic CLI replacement stays fail closed |
| Master-key rotation requires Owner password/MFA and current recovery-package authentication | PASS | Existing throttled re-authentication service, recovery-package verifier, and CLI command tests |
| Rotation requires quiescence, verified backup, maintenance/row locks | PASS | Application service and MariaDB integration tests |
| Complete re-encryption, restrictive key swap, redacted audit, crash/power-loss-safe resolution | PASS LOCAL | Success, expected-key, active-delivery refusal, concurrent-reader refusal, pre/post-commit crash, file/directory barriers, and failed-barrier rollback tests |

## 3. Security, privacy, licensing, and accessibility

| Check | Status | Evidence |
| --- | --- | --- |
| Production dependency license allowlist passes | PASS | `tools/quality/check-license-policy.php` over all locked production packages |
| Candidate SPDX rejects missing license decisions | PASS | `PackageInspectorTest` rejects `NOASSERTION` |
| Secret/private-data current-tree and reachable-history scans | PASS LOCAL | Aggregate scans pass; exact committed head and CI remain required |
| Fake-mail isolation and external SMTP negative probe | PASS LOCAL; PENDING CI | Fresh source image on an internal Docker network rejects external port 25; all 232 tests pass with 8,098 assertions; current-head CI must repeat it |
| Retention/suppression/recipient privacy regression | PASS LOCAL | Complete walkthrough-repair aggregate passes at 232 tests and 3,101 assertions |
| Browser support and WCAG 2.2 AA target documented | PASS | [accessibility-browser-support.md](accessibility-browser-support.md) |
| Static accessibility acceptance | PASS LOCAL | Twig/CSS structural acceptance passes in the complete PHPUnit aggregate |
| Human keyboard, zoom, contrast, screen-reader and supported-browser acceptance | PENDING MANUAL | Must be recorded by the acceptance owner; automation cannot substitute |
| No unresolved critical/high security finding or critical Doctor result | PENDING | Final diff review, CI, candidate clean-install Doctor evidence |

## 4. Release-train and operations acceptance

| Check | Status | Evidence |
| --- | --- | --- |
| Version `1.0.0`, target schema `20260828000000` | PASS | Container parameters and Phase 13 platform test |
| Direct accepted sources exactly `0.1.0`, `0.2.0`, `0.3.0`, `0.4.0` | PASS LOCAL | Exact integrated source trees become disposable-key signed runtime packages; target updater consumes and matches each installed manifest |
| Signed predecessor evidence is mandatory before operational mutation | PASS LOCAL | Missing/tampered evidence, installed drift, legacy-as-target, and source/pointer mismatch tests fail before quiescence or backup; [ADR 0013](adr/0013-authenticated-predecessor-and-durable-publication.md) |
| Unsupported source, downgrade, same-version, tamper and wrong signature fail closed | PASS LOCAL | Release/update matrix and deterministic candidate-tooling gate pass |
| Clean immutable install | PASS LOCAL | Production-only install, standalone verification, and root-run ownership gate; fresh `emailer` identity cannot chmod published code |
| Interrupted update, failed health, rollback/restore | PASS LOCAL | Complete release-update/candidate gates pass |
| Reference load profile within documented bounds | PASS LOCAL | Bounded 2,000-recipient profile passes in the complete aggregate |
| Backup create/verify/restore and recovery regression | PASS LOCAL | Complete backup, recovery and rotation regression passes |
| Fresh restricted database initializes migration metadata after credential verification | PASS LOCAL | Empty-schema regression applies all 13 migrations, rejects wrong input and permanent setup lock, and remains idempotent |
| Isolated restore evidence authenticates actual comparison and imports without restoring live data | PASS LOCAL | Every contributed row/asset compared; tamper, unsafe files, damaged artifact, future/mismatched receipt, active delivery, failed comparison/rollback, and replay covered |
| Installer selects the public-port owner and preserves existing address bindings | PASS LOCAL | Mixed Apache/Nginx listener fixtures; unknown, ambiguous, and conflicting choices fail before provisioning; real repaired-server rehearsal remains pending |
| Client install/update/rollback/handover documentation complete | PASS | [CLIENT-INSTALL-1.0.0.md](CLIENT-INSTALL-1.0.0.md), [CLIENT-UPDATE-1.0.0.md](CLIENT-UPDATE-1.0.0.md) |

## 5. Candidate, review, and approval

| Check | Status | Evidence |
| --- | --- | --- |
| Complete `composer check` on exact committed head | PENDING | Record command and totals in Phase 13 report |
| Required GitHub CI matrix green on exact PR head | PENDING | PR checks |
| Independent review complete with no unresolved critical/high finding | RE-REVIEW REQUIRED | Review of `3fced5dc4ed155314c51eb226b444201a3ed1132` found four high blockers; bounded working-tree repairs require a new exact-head review |
| Operator-signed deterministic `1.0.0` candidate independently verified | PENDING AUTHORIZATION | Requires exact-head authorization and external key/public/output paths |
| Candidate archive/manifest/checksum/signature/SBOM and verifier hashes recorded | PENDING AUTHORIZATION | External candidate record; outputs stay outside Git |
| Exact PR head approved and merged | PENDING AUTHORIZATION | Anton must name repository, Phase 13, PR, and full head SHA |
| Local `main` clean and synchronized to merged `origin/main` | PENDING | Post-merge verification |

Phase 13 cannot be reported complete while any required item above remains
pending. Candidate acceptance does not authorize tagging, publication,
deployment, DNS/provider changes, client installation, credential rotation, or
live SMTP.
