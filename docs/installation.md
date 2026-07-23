# Installation

**Status:** Design baseline only. Phase 0 is not installable.

There is no `composer.json`, framework runtime, container stack, console, web
entry point, or database migration in Phase 0. Do not point a web server at this
repository or attempt production setup. Phase 1 will add and test local
bootstrap instructions; Phases 2 and 3 will add first-run installation.

## Target prerequisites

- Supported Linux host.
- PHP 8.4 or a newer project-tested supported branch with the extensions in the
  master specification.
- Composer 2 for build/install, with a committed lockfile.
- MariaDB 10.11 or a newer project-tested supported release.
- Nginx or Apache with TLS; document root exactly `public/`.
- A supervised CLI process via systemd or Supervisor, or the documented
  restricted cron fallback.
- A CLI scheduler.
- Private data directory outside `public/`, preferably outside the release.
- External master-key path outside `public/`, preferably under `/etc`, owned by
  the application operator and not Git.

The exact compatibility matrix is resolved in Phase 1 and checked by the
Configuration Doctor in Phase 8.

## Target production layout

```text
/srv/emailer/releases/<version>/   immutable application release
/srv/emailer/current               operator-managed release pointer
/srv/emailer-private/              uploads, exports, backups, setup/private state
/etc/emailer/master.key            external master key
```

These are examples, not commands and not mandatory literal paths. Only the
release's `public/` subdirectory is web-served. The web process must not write
application source or Git metadata.

## Installation safety gates

Before setup can collect a password or secret, the implementation must verify:

- supported runtime and required extensions;
- `public/` document-root boundary;
- TLS and explicit trusted-proxy handling;
- safe ownership/modes for private and key directories;
- MariaDB driver/connectivity;
- writable cache/private locations;
- worker/scheduler capability;
- fake-only mail configuration in development/test.

Real database or mail credentials are entered only into operator-controlled
runtime configuration or the HTTPS/CLI setup flow once implemented. They never
belong in `.env.example`, documentation, prompts, screenshots, Git, CI output,
or issue text.

## Completion criteria

Installation is complete only after ownership proof, migrations, external key,
individual Owner, encrypted transport configuration, privacy/delivery policy,
worker/scheduler check, final critical Doctor pass, and private installation
lock. Until then, normal routes and delivery remain unavailable.

See [first-run-setup.md](first-run-setup.md) for the designed sequence. No
installation manual action is available or required in Phase 0.
