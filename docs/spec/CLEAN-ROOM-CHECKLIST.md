# Clean-room compliance checklist

This checklist is mandatory before every phase and release.

Keep this canonical copy reusable. Record pass, fail, not-applicable rationale,
evidence, and reviewer in a phase-specific report; do not mark an item complete
without observable evidence or an explicit operator attestation. Any unresolved
secret/private-data, origin, or network-safety item blocks the phase.

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

## Release and update evidence

- [ ] Every supported update path uses the exact signed predecessor package and
  original sidecars, not only a version string, source checkout, or directory name.
- [ ] The installed predecessor file set and digests match its signed manifest
  before delivery quiescence, backup, migration, or pointer mutation.
- [ ] Legacy package parsing is restricted to same-version predecessor evidence
  and cannot relax the current target-package contract.
- [ ] Security-sensitive file contents are `fsync`ed before publication and the
  containing directory is `fsync`ed after namespace changes.
