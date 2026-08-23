# Bounded implementation roadmap

The canonical phase boundaries are in
[PHASE-JOBS.md](spec/PHASE-JOBS.md). This roadmap links designs and expected
evidence; it does not authorize chaining phases.

| Phase | Outcome | Key design inputs | Exit evidence |
| --- | --- | --- | --- |
| 0 | Clean-room foundation and design lock | Master spec, ADRs, architecture, threat/security model, modules, data, routes, gates | Documentation set, repository safeguards, tree/history/private-key/PII scans, diff check |
| 1 | Reproducible Symfony platform | ADR 0002/0003/0004/0008/0010; quality gates | Locked Composer install, MariaDB/Mailpit dev stack, CI, coarse liveness, aggregate check, safe artifact |
| 2 | Ownership proof and setup state machine | Setup threats TM-01/TM-02/TM-12; setup routes | Proof edge/race tests, HTTPS gate, permanent lock/reset behavior, redacted logs |
| 3 | Secure wizard, database, key, Owner, mail setup | Secret hierarchy, initial data model, setup route inventory | Crypto/fail-closed tests, MariaDB migrations, fake SMTP only, irreversible setup completion |
| 4 | Identity, admin shell, sessions and audit | Role model, admin route inventory, TM-06–TM-09 | Generated route-role/CSRF matrix, session tests, last-Owner invariant, audit redaction |
| 5 | Contacts, suppression, imports and templates | Contacts/Templates boundaries, data classification, TM-10–TM-13 | Streaming/bounds/injection tests, safe assets and previews, no sending |
| 6 | Campaign, ledger, queue, worker and delivery | ADR 0006/0007/0009, delivery flow/data model | Fake-SMTP end to end, concurrency/restart/race tests, honest durable progress |
| 7 | MVP unsubscribe and recipient safety | Public route inventory, recipient-token model, TM-13/TM-17 | Token/privacy tests, RFC 8058 behavior, immediate suppression |
| 8 | MVP operations, backup, package/update tooling | ADR 0011, Health/Backup boundaries, TM-20/TM-23–TM-25 | Critical sending gates, signed immutable package, install/update/restore drills |
| 9 | MVP `0.1.0` release acceptance | Implemented MVP design and Phase 1–8 reports | Reproducible signed archive, SBOM/checksum, clean install and update rehearsal, MVP RC report |
| 10 | Update `0.2`: view-in-browser and optional tracking | Recipient tokens, privacy model, TM-18/TM-19 | Tracking-off proof, safe redirects, retention, tested `0.1.x` update |
| 11 | Update `0.3`: optional RSVP | RecipientActions/Analytics boundaries | Token/input/privacy tests and tested supported-version update |
| 12 | Update `0.4`: full Doctor, retention and advanced operations | Full Health/Backup requirements, TM-20/TM-24/TM-25 | Remaining Doctor checks, operational dashboard, retention and backup automation |
| 13 | Version `1.0.0` hardening and acceptance | Entire design and accumulated phase reports | Full aggregate and safety gates, release-train upgrade matrix, v1 RC report |

Phase 9 is the current implementation boundary and produces the first
client-installable MVP candidate without publishing or deploying it. Phases
10–13 remain unstarted and require separate authorization; Phase 13 retains the
complete Version 1 definition.

## Release train

| Candidate | Owning phase | Client-visible increment | Required installation evidence |
| --- | --- | --- | --- |
| `0.1.0` MVP | 9 | Campaign sending, unsubscribe, critical readiness gates, backup/restore, package/update tooling | Clean-server install plus signed release-candidate update rehearsal |
| `0.2.0` | 10 | View-in-browser and opt-in open/click tracking | Clean install and supported `0.1.x` update |
| `0.3.0` | 11 | Optional RSVP | Clean install and supported `0.2.x` update |
| `0.4.0` | 12 | Full Doctor, retention, operational dashboard, backup automation | Clean install and supported `0.3.x` update |
| `1.0.0` | 13 | Complete master-specification Version 1 acceptance | Clean install and all supported release-train update paths |

Every post-MVP phase delivers its feature set through the package/update
contract established in Phase 8. Building an update candidate is part of phase
acceptance; publishing or installing it remains a separately authorized
operator action.

## Per-phase workflow

1. Re-read the three canonical specification documents and the relevant ADRs.
2. Complete the clean-room checklist before inspecting or creating
   implementation artifacts.
3. Revalidate time-sensitive official runtime/dependency support.
4. Implement only the named phase and its required migrations/tests.
5. Run phase and aggregate checks available at that point.
6. Update architecture, threat model, operator docs, route inventory, data
   model, and open decisions affected by the work.
7. Inspect the complete diff, migrations, test output, and scan findings.
8. Report changes, incomplete work, migrations, manual actions, and decisions.
9. Stop. A separately approved job begins the next phase.

Commit, merge, push, release, deployment, DNS/provider changes, credential
rotation, and production SMTP contact are separate operator actions and are not
implied by completing a phase.
