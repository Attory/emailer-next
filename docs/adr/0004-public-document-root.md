# ADR 0004: `public/` is the only web document root

- **Status:** Accepted
- **Date:** 2026-07-23
- **Decision owners:** Emailer Next maintainers
- **Related phase:** 0

## Context

The project contains source, Git metadata, configuration, dependencies, logs,
uploads, exports, backups, setup state, and secret-key material. Server
misconfiguration that serves the repository root would make several of these
remotely discoverable.

## Decision

Nginx and Apache production examples set the document root to the repository's
`public/` directory and route dynamic requests through `public/index.php`.
Private/runtime/secret paths are outside `public/`; production should place
them outside the release directory where practical.

The application and Doctor refuse production operation or block sending when
the boundary cannot be verified. Release artifacts use an explicit runtime
allowlist, and exposure tests probe forbidden paths.

## Consequences

- Hosts that cannot choose a document root are unsupported unless an equally
  strong reviewed deployment pattern is added by ADR.
- Public uploaded images require a dedicated non-executable allowlisted area.
- Operators receive exact Nginx/Apache configuration and verification commands.

## Security and privacy impact

This is a primary control against disclosure of credentials, source, private
recipient data, logs, backups, and Git history. File permissions and artifact
exclusions remain necessary defense in depth.

## Alternatives considered

- Serving the repository root with deny rules was rejected because missed file
  types and server-rule drift fail open.
- Copying ad hoc public files to a shared hosting directory was rejected because
  it weakens immutable artifact and path verification.

## Validation

Functional/deployment tests request `.git`, `.env`, config, dependency, log,
backup, JSON, private, setup, and key paths through representative Nginx and
Apache profiles. Doctor checks the resolved document root and storage paths.
