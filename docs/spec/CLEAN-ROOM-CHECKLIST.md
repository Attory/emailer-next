# Clean-room compliance checklist

This checklist is mandatory before every phase and release.

## Repository origin

- [ ] The workspace was created from a new empty directory or the approved specification starter.
- [ ] No legacy Emailer remote exists.
- [ ] No temporary source-repository remote exists.
- [ ] No legacy commit, tag, branch, submodule, subtree, patch, archive, or vendor directory exists.
- [ ] No file was copied from the legacy project.

## Secrets and private data

- [ ] No real SMTP credential.
- [ ] No administrator password.
- [ ] No API token.
- [ ] No private key.
- [ ] No recovery code.
- [ ] No production `.env`.
- [ ] No real recipient address or recipient list.
- [ ] No production log, queue, backup, or export.
- [ ] No production template containing private content.
- [ ] No secret/private data in current tree.
- [ ] No secret/private data in reachable history.
- [ ] No secret/private data in build artifacts.
- [ ] No secret/private data in test snapshots or logs.

## Network safety

- [ ] Tests use Mailpit/fake SMTP.
- [ ] Test configuration cannot resolve or contact production SMTP.
- [ ] No production webhook, DNS modification, or external account action.
- [ ] No production deployment.

## Architecture

- [ ] One installation, one organisation.
- [ ] No tenant IDs or tenant switching.
- [ ] Only `public/` is web-served.
- [ ] Background sending is CLI-worker only.
- [ ] Every state-changing admin action has auth, authorization, and CSRF.
- [ ] Public recipient endpoints use purpose-scoped opaque tokens.
- [ ] Secrets use the approved encrypted-record design.
- [ ] Logs and diagnostics are redacted.

## Review

- [ ] Diff inspected.
- [ ] Tests inspected.
- [ ] Migrations inspected.
- [ ] Documentation updated.
- [ ] Manual operator actions identified.
- [ ] Next phase not started automatically.
