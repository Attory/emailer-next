# Installation

**Status:** Phase 1 local-development bootstrap only. Production installation is
not implemented.

Phase 1 can create a safe local Symfony/MariaDB/Mailpit platform. It cannot
create an Emailer installation, Owner, transport credential, contacts,
templates, or campaigns, and it cannot send production mail. Phases 2 and 3 own
the first-run installation flow.

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

## Future production prerequisites

The target remains PHP-FPM behind Nginx or Apache with TLS and document root
exactly `public/`, MariaDB, external private storage, an external restrictive
master-key path, a supervised CLI worker or documented cron fallback, and a CLI
scheduler. Those operational profiles, ownership proof, key creation, runtime
credentials, and Configuration Doctor gates are not implemented by Phase 1.

See [first-run-setup.md](first-run-setup.md) for the designed later sequence.
