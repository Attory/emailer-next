# Installation

**Status:** The 1.0.5 candidate uses schema `20261013000000`. Use the ordered
[1.0.5 client-server installation manual](CLIENT-INSTALL-1.0.5.md) for an
approved candidate. A fresh installation defaults to High security; Normal is
available only after an Owner selects it through the authenticated settings UI.

The current repository can create a completed installation with an
external key, initial Owner, exact local Mailpit test evidence, sender identity,
safe policies, irreversible lock, individual administrator login, session and
account management, MFA, recovery, redacted audit UI, contacts, lists, private
imports, suppression, immutable templates, durable campaigns, supervised
delivery, privacy-safe unsubscribe, encrypted backup/restore, critical sending
Doctor gates, and signed immutable package/update tooling. Production
installation still requires operator-provided infrastructure, secrets,
provider configuration, and explicit deployment approval.

The approved plan makes Phase 9 the first client-installable `0.1.0` MVP after
campaign delivery, unsubscribe, critical readiness gates, backup/restore, and
signed package/update tooling are complete. Phases 10–13 then deliver optional
features and deeper operations as tested installable updates. No current tree
or planning document is itself a production package.

## Tested platform matrix

The Phase 1 CI matrix is:

| Component | Tested versions |
| --- | --- |
| PHP | 8.4.24 and 8.5.9 |
| Symfony | locked 7.4 LTS packages (current components through 7.4.17) |
| MariaDB | 10.11.19 and 11.8.9 |
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
displayed instruction to create its one fixed `.emailer-install-proof` with
mode `0600` and the displayed code only, then submit the CSRF-protected
verification form. Development defaults to the application root. Immutable
client releases configure `EMAILER_INSTALL_PROOF_FILE` in a service-owned setup
directory outside the release; the supplied root helper creates the file with
the correct identity and mode. Delete and recreate any rejected proof as a
regular file; links, files over 256 bytes, and group/world-writable files are
rejected.

After success the browser session rotates and `/setup/preflight` requires
HTTPS. `bin/console emailer:setup:install` performs the same credential-free
preflight. The HTTPS-independent full alternative is
`bin/console emailer:setup:install --complete` from a protected interactive
terminal. It accepts secrets only through hidden prompts, never command-line
options; uses the same database, organisation, Owner, local-Mailpit, policy,
and finalization services as the web wizard; and never prints MFA enrollment
material. Enrol Owner MFA from the authenticated HTTPS UI before production.

## Phase 3 runtime configuration and completion

Before web setup, operators supply `DATABASE_URL`, `EMAILER_PRIVATE_DIR`, and
`EMAILER_SECRET_KEY_FILE` through external runtime configuration. Production
refuses an omitted, relative, application/release/public, symlink-directed, or
unsafe-ancestor key path. The complete existing chain is checked; the immediate
parent must be owned by the effective service user with exact mode `0700`, and
the key is mode `0600`. For the client layout use
`/var/lib/emailer/keys/master.key`; do not combine its parent with root-owned
runtime configuration. Development Compose
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
proxy access-log redaction for the opaque `/r/` segment. Phase 8 now supplies
Doctor, backup/restore, recovery, housekeeping, and signed package tooling.
The Phase 11 operator-held `0.3.0` candidate is accepted but unpublished; use
[CLIENT-UPDATE-0.3.0.md](CLIENT-UPDATE-0.3.0.md) for the supported `0.2.x`
update procedure. Installation or deployment remains a separate operator
action.

## Clean-server `1.0.0` installation

The authoritative step-by-step procedure is
[CLIENT-INSTALL-1.0.0.md](CLIENT-INSTALL-1.0.0.md). It covers change authority,
supported versions, service accounts and paths, database provisioning, public
key pinning, standalone pre-extraction verification, immutable extraction,
protected runtime configuration, PHP-FPM/web-server configuration, setup,
workers, scheduler, backup/restore drills, Doctor, accessibility acceptance,
go-live, rollback, and handover.

The historical `0.1.0` outline below remains useful release-train context but
must not replace the `1.0.0` client manual.

1. Provision a supported Linux host with PHP-FPM 8.4.24 or 8.5.9 and every
   extension in `composer.json`, MariaDB 10.11.19 or 11.8.9, Nginx or Apache,
   a non-root `emailer` process account, a supervised worker or the bounded cron
   fallback, and a CLI scheduler. Do not install a production Node.js runtime.
2. Create separate operator-owned paths for immutable releases, external
   runtime configuration, private writable data, backups, the master key,
   recovery package, and pinned release public key. The web process may write
   only the approved private/runtime paths, never source or release files.
3. Obtain the archive, manifest, checksum, SBOM, signature, and public-key
   fingerprint through the approved release channels. Independently verify and
   pin the public key as described in [release-signing.md](release-signing.md).
4. Supply placeholder-free runtime values through a protected service
   environment file outside releases. Never place a credential on a command
   line or in this repository. Set the document root exactly to the future
   `current/public` path and keep the virtual host unavailable externally until
   verification completes.
5. Run `php bin/console emailer:release:inspect
   /private/path/emailer-next-0.1.0.zip`, then `php bin/console
   emailer:release:install /private/path/emailer-next-0.1.0.zip`. The installer
   verifies all signed sidecars before extracting into a
   new immutable `0.1.0` directory.
6. Point service working directories to the atomic `current` link, apply
   migrations through `Version20260824010000`, and complete the HTTPS ownership
   proof and first-run setup. Setup begins with local Mailpit evidence; configure
   authenticated TLS production SMTP only afterward through the protected Owner
   workflow.
7. Start worker and scheduler, run `emailer:doctor:run --json`, create and
   verify an encrypted backup and separate recovery package, verify the web
   exposure probes, and keep delivery quiesced until every critical finding is
   cleared. A real provider test or DNS change is a separate action.

For Version 1 updates, keep the releases root and every semantic-version tree
root-owned. Authenticate the target archive with the independently pinned key
and separately approved verifier hashes before extracting target code or
installing its systemd unit. The root-controlled target updater reads an exact
source version from `/etc/emailer/update.env`, verifies it against the current
pointer, and publishes the new release without granting the `emailer` identity
any release-write capability.

The Phase 9 rehearsal performs these steps only against disposable synthetic
services and Mailpit. It does not deploy a client server.

## Phase 8 operations configuration

Apply migrations through `DoctrineMigrations\Version20260824010000`. Provide
absolute external paths for `EMAILER_RECOVERY_KEY_FILE`,
`EMAILER_RELEASE_PUBLIC_KEY_FILE`, and `EMAILER_RELEASES_DIR`; none may be under
`public/` or an immutable release.
The recovery package and master key must not share backup custody, and the
release signing private key never belongs on the client server.

Run `emailer:doctor:run` before production sending. Critical findings block
queue, schedule, and scheduled materialisation. Use the CLI backup, restore,
recovery, housekeeping, and release commands documented in the operator,
backup, and upgrade runbooks. No Phase 8 command publishes a package, creates a
tag, deploys a server, changes DNS/provider state, or contacts live SMTP.

`EMAILER_PRIVATE_DIR/template-assets` is persistent mutable content, not part of
an immutable application release. The application serves only validated random
asset filenames from the `/template-assets/` route.

## Future production prerequisites

The target remains PHP-FPM behind Nginx or Apache with TLS and document root
exactly `public/`, MariaDB, external private storage, an external restrictive
master-key path, a supervised CLI worker or documented cron fallback, and a CLI
scheduler. Production transport, authenticated changes, worker heartbeats, and
full Configuration Doctor gates are not implemented by Phase 3.

See [first-run-setup.md](first-run-setup.md) for the implemented setup sequence
and remaining later-phase boundaries.

## Ripe 1.0.6 edition compatibility

The bounded Light 1.0.0 transition and native Ripe 1.0.5 update are documented
in [CLIENT-LIGHT-UPGRADE-1.0.6.md](CLIENT-LIGHT-UPGRADE-1.0.6.md) and
[RELEASE-1.0.6-IMPLEMENTATION.md](RELEASE-1.0.6-IMPLEMENTATION.md). The existing
`emailer:setup:install` CLI supports read-only inspection, execution, unfinished
recovery, acceptance and isolated old-Light-backup export. No HTTP update or
restore route is introduced. Contacts/lists remain independent of campaign-owned
Light recipients. Delivery remains held until explicit accepted readiness/resume.
