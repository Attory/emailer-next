# Backup and restore

**Status:** Design only; implementation and first restore drill are Phase 8.

## Backup scope

A versioned application backup contains:

- database dump;
- templates and immutable versions;
- approved uploaded assets;
- non-secret configuration metadata;
- migration and application versions;
- integrity manifest.

It excludes cache, sessions, temporary imports, ordinary logs unless explicitly
approved, dead transient files, and the plaintext master key. SMTP/provider
secrets remain encrypted records and are useful only with the separately
protected key.

## Protection

Backups are encrypted, integrity checked, randomly named, stored outside
`public/`, and written with restrictive permissions. Success, failure, and
verification are recorded without exposing a path or private contents through
public/admin diagnostics. Spreadsheet exports remain private and formula
neutralized.

The plaintext master key is never part of the ordinary backup. A separate
CLI-only recovery export encrypts a versioned key package using an
operator-provided strong passphrase that is never stored or logged. Doctor warns
until a verified recovery package is recorded.

## Planned backup procedure

1. Check free space, key access, schema/application version, and destination.
2. Obtain a consistent database/filesystem view.
3. Build from an explicit content allowlist.
4. Encrypt, write manifest and digest, then verify by reading it back.
5. Record redacted metadata and retention eligibility.
6. Transfer externally only through a separately approved adapter/configuration.

## Planned restore procedure

Restore is CLI-only and requires maintenance mode, Owner/operator approval,
format/version/integrity/key/free-space preflight, and a pre-restore backup. It
restores into a staging namespace where practical, validates constraints and
suppression, then switches transactionally or follows a tested rollback.

The restore command never obtains a backup path or passphrase from an HTTP
request and never prints decrypted contents or key material.

## Drill and policy

Phase 8 must test corruption, wrong passphrase/key, partial data, incompatible
version, insufficient space, interrupted restore, rollback, and preservation of
suppression. Operators set RPO/RTO, backup frequency, retention, external
destination, and drill interval outside Git after reviewing legal and business
needs.
