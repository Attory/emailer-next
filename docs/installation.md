# Installation

**Status:** Phase 7 campaign delivery and unsubscribe are implemented. Client installation/recovery/update tooling remains Phase 8–9.

The current repository can create a completed installation with an
external key, initial Owner, exact local Mailpit test evidence, sender identity,
safe policies, irreversible lock, individual administrator login, session and
account management, MFA, recovery, redacted audit UI, contacts, lists, private
imports, suppression, immutable templates, durable campaigns, supervised
delivery, and privacy-safe unsubscribe. It has no backup engine, sending Doctor,
immutable update package, or supported production deployment yet.

The approved plan makes Phase 9 the first client-installable `0.1.0` MVP after
campaign delivery, unsubscribe, critical readiness gates, backup/restore, and
signed package/update tooling are complete. Phases 10–13 then deliver optional
features and deeper operations as tested installable updates. No current tree
or planning document is itself a production package.

## Tested platform matrix

The Phase 1 CI matrix is:

| Component | Tested versions |
| --- | --- |
| PHP | 8.4.23 and 8.5.8 |
| Symfony | locked 7.4 LTS packages (core 7.4.14 at Phase 1 completion) |
| MariaDB | 10.11.18 and 11.8.8 |
| Composer | 2.10.2 |
| Mailpit | 1.30.5 |

PHP 8.4 is the minimum. A newer PHP or MariaDB branch is not supported merely
because it can start; it must be added to and pass the matrix. See
[ADR 0002](adr/0002-php-84-and-symfony-74-lts.md),
[ADR 0003](adr/0003-mariadb-primary-store.md), and
[ADR 0010](adr/0010-phase-1-toolchain-and-test-isolation.md).

## Safe local bootstrap

Prerequisites:

- a supported Linux Docker host;
- Docker Engine with the Compose plugin;
- `curl` for the final loopback liveness check;
- no local environment file, production credential, or recipient data.

Run:

```bash
./bin/bootstrap
```

The command:

1. builds the application image from the pinned PHP patch and Composer image;
2. verifies and installs the pinned Gitleaks binary;
3. starts the pinned MariaDB and Mailpit services;
4. refreshes the dependency volume from a clean `composer.lock` install;
5. runs the complete `composer check` pipeline and baseline migration;
6. starts the application and verifies the exact coarse liveness response.

On success, the scaffold is available only on
`http://127.0.0.1:8080`; Mailpit's UI is only on
`http://127.0.0.1:8025`. MariaDB has no host port. The Compose database
passwords and application secret are fixed, obvious local-only placeholders.
They are not suitable for production.

Stop containers while preserving the local test database:

```bash
docker compose down
```

Deleting Compose volumes irreversibly discards local test state and is not part
of bootstrap.

## Configuration boundary

`.env.example` is a placeholder schema, not a credential store. Symfony Dotenv
loading is disabled; Compose/CI pass runtime values explicitly. A non-container
operator must likewise provide values through an external runtime mechanism and
must not copy real secrets into this repository.

In `APP_ENV=test`, kernel construction rejects every mail transport except
credential-free Mailpit on port 1025 using the `mailpit` service name or a
loopback address. Development Compose also defaults to Mailpit. No test or
bootstrap step contacts live SMTP.

## Phase 1 migration

`DoctrineMigrations\Version20260723000000` is the platform baseline. It executes
`SELECT 1` in both directions to prove MariaDB migration wiring and creates no
business table. Doctrine itself creates its migration-version metadata table
when migrations run. No manual production migration is authorized in Phase 1.

## Phase 2 setup entry

An unlocked deployment exposes `/setup` and coarse liveness only. Follow the
displayed instruction to create `<APPLICATION_ROOT>/.emailer-install-proof`
with mode `0600` and the displayed code only, then submit the CSRF-protected
verification form. Delete and recreate any rejected proof as a regular file;
links, files over 256 bytes, and group/world-writable files are rejected.

After success the browser session rotates and `/setup/preflight` requires
HTTPS. This phase does not ask for a database password, master key, Owner data,
or mail settings. The CLI alternative is `bin/console emailer:setup:install`;
it also stops before credentials and does not create a completed installation.

## Phase 3 runtime configuration and completion

Before web setup, operators supply `DATABASE_URL`, `EMAILER_PRIVATE_DIR`, and
`EMAILER_SECRET_KEY_FILE` through external runtime configuration. Production
refuses an omitted, relative, application/release/public, symlink-directed, or
unsafe-ancestor key path. The complete existing chain is checked; setup creates
the immediate parent as mode `0700` and key as mode `0600`. Development Compose
uses a separate `/emailer-secrets` volume. Never pre-create the key with
content, put it in Git, pass it on a command line, or include it in an ordinary
backup.

After filesystem proof and HTTPS preflight, the database form verifies that
the submitted host/port/database/user match `DATABASE_URL`, verifies the
password without retaining it, and applies migrations. Optional TOTP recovery
values are displayed once and cannot be recovered from setup state.

Mail setup accepts only plaintext, unauthenticated `mailpit` or loopback on port
1025 and sends only to reserved `phase3-recipient@example.test`. STARTTLS,
implicit TLS, usernames, passwords, and every live hostname are rejected. The
stored row exactly matches that probe and records that TLS was not used.
Completion runs critical checks and writes the private lock; all subsequent
`/setup` requests return 404.

Phase 3 migrations are `DoctrineMigrations\Version20260815000000` and the
mail-integrity repair `DoctrineMigrations\Version20260816000000`. MariaDB DDL
may auto-commit, so migration failure is a blocking operator condition; never
manually mark it complete.

## Phase 4 authentication configuration

Phase 4 adds forward-only migrations `DoctrineMigrations\Version20260817000000`
and security repair `DoctrineMigrations\Version20260818000000`. Run them with
the normal reviewed migration gate; do not hand-create session, throttle,
reset, MFA, or audit fields. Configure `TRUSTED_PROXIES` as an explicit
comma-separated allowlist for the actual TLS proxy hops. The default trusts
loopback only. Configure `TRUSTED_HOSTS` as a strict regular expression matching
the canonical host; the safe default matches only loopback hosts. Never use a
wildcard or an unreviewed private network range. Forwarded host values are not
trusted.

The final trusted TLS proxy must replace, never append, the client forwarding
header. For a single Nginx hop use
`proxy_set_header X-Forwarded-For $remote_addr;`; do not use
`$proxy_add_x_forwarded_for`, because it preserves attacker-supplied prefixes
and defeats source-keyed login, identity-page, and reset limits. Multi-hop
deployments require a separately reviewed trusted-proxy chain.

Set `APP_SECRET` to at least 16 bytes of high-entropy secret material before
installation. Rotation changes the purpose-separated HMAC identities used for
sessions, throttles, and source attribution: treat rotation as a maintenance
event that invalidates all sessions and makes earlier throttle/source hashes
unmatchable. Do not rotate it casually or use a public/default value.

Serve administration through verified HTTPS. The `EMAILERSESSID` cookie is
host-only, path `/`, `Secure`, `HttpOnly`, and `SameSite=Lax`. After installation,
safe HTTP identity/admin requests redirect to the stored canonical HTTPS origin
and unsafe HTTP methods are rejected before credentials or session state are
processed. HSTS is emitted only for requests Symfony has verified as secure. Password
reset mail remains restricted to the persisted local Mailpit transport; Phase 4
does not unlock or test a live provider.

## Phase 5 content storage

Apply forward-only `DoctrineMigrations\Version20260819000000` through the
reviewed migration gate. The PHP runtime now requires DOM and GD with JPEG,
PNG, and WebP support. `EMAILER_PRIVATE_DIR/imports` must remain private and
non-public; template assets are the only uploaded files under `public/`, and
only after MIME/dimension checks plus decoder re-encoding to a random raster
filename. Do not place imports in `public/` or treat the fake template test as
a production send. Phase 5 requires no SMTP/provider credential or external
service.

## Phase 6–7 delivery and recipient safety

Apply forward-only `DoctrineMigrations\Version20260822000000` and
`DoctrineMigrations\Version20260823000000` through the reviewed migration gate.
Phase 6 requires a supervised worker plus scheduler, or the documented bounded
cron fallback. Phase 7 issues encrypted-key, versioned unsubscribe tokens and
serves `/r/<opaque-token>/unsubscribe` without an admin session or cookie.

Every production message has a manual unsubscribe URL. Leave the RFC 8058 DKIM
attestation disabled unless the provider is known to sign both unsubscribe
headers; the application does not create that provider signature. Configure
proxy access-log redaction for the opaque `/r/` segment. These capabilities do
not make the tree client-installable: Phase 8 Doctor, backup/restore, and package
tooling plus Phase 9 acceptance still block production installation.

## Future production prerequisites

The target remains PHP-FPM behind Nginx or Apache with TLS and document root
exactly `public/`, MariaDB, external private storage, an external restrictive
master-key path, a supervised CLI worker or documented cron fallback, and a CLI
scheduler. Production transport, authenticated changes, worker heartbeats, and
full Configuration Doctor gates are not implemented by Phase 3.

See [first-run-setup.md](first-run-setup.md) for the implemented setup sequence
and remaining later-phase boundaries.
