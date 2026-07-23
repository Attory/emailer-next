# Mitek → Codex master bootstrap job

Use this prompt only in a **new, empty, clean-room workspace** named `emailer-next`. The intended final GitHub repository is `Attory/emailer-next` after the compromised repository has been deleted.

```text
Create the clean-room foundation for Emailer Next.

Hard constraints:

- This is a new self-hosted email campaign product.
- One installation serves one organisation on one server.
- Multiple individual administrator accounts are allowed.
- It is not multi-tenant.
- Do not clone, inspect, import, copy, add as a remote, or reuse any legacy Emailer repository or temporary source repository.
- Do not use legacy code, Git history, templates, PHPMailer files, private data, credentials, or fixtures.
- Do not contact production SMTP.
- Do not create, request, display, or store a real credential.
- Do not push, merge, release, deploy, change DNS, or alter external accounts.

Read these repository documents as the source of truth:

1. docs/spec/EMAILER-NEXT-MASTER-SPEC.md
2. docs/spec/PHASE-JOBS.md
3. docs/spec/CLEAN-ROOM-CHECKLIST.md

Perform Phase 0 only: clean-room foundation and design lock.

Required output:

- architecture documentation;
- threat model;
- security model;
- module boundaries;
- database/entity design;
- route/public-surface design;
- ADRs for all selected architecture decisions;
- CI and quality-gate design;
- documentation skeleton;
- safe .gitignore, .gitattributes, .editorconfig, and placeholder-only .env.example;
- clean-room compliance checklist;
- initial implementation roadmap linked to the bounded phases.

Run the Phase 0 checks. Report exact commands and results. State all open decisions. Stop after Phase 0. Do not scaffold or implement application functionality unless the phase document explicitly authorizes it.
```
