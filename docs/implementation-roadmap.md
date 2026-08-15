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
| 7 | Public recipient actions and optional tracking/RSVP | Public route inventory, recipient-token model, TM-17–TM-19 | Token/redirect/privacy tests, RFC 8058 behavior, suppression after unsubscribe |
| 8 | Doctor, backups, restore, retention and operations | Health/Backup boundaries, TM-20/TM-24/TM-25 | Every sending gate, redacted report, clean restore drill, retention reruns |
| 9 | Release hardening and v1 acceptance | Entire design and accumulated phase reports | Full aggregate check, mandatory safety properties, SBOM/checksum, accessibility/load review, RC report |

Phase 2 is the current completed boundary. Phases 3–9 remain unstarted; this
status statement does not authorize the next phase.

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
