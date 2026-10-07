# Install Emailer Next 1.0.0 on a client server

This is the production installation procedure for a new, single-organisation
Emailer Next `1.0.0` server. It assumes a supported Linux host, shell/root
access for infrastructure work, and an approved signed release-candidate
bundle. It does not authorize a deployment, DNS change, provider change, or
live SMTP test by itself.

Use synthetic values while rehearsing. Never paste a real password, recipient
list, master key, recovery passphrase, release signing private key, or SMTP
credential into Git, chat, tickets, screenshots, shell history, or command
arguments.

## 1. Record the installation change

Before touching the server, record:

- the client installation identifier and approved maintenance window;
- the approved `1.0.0` source commit and archive SHA-256;
- the release public-key SHA-256 fingerprint obtained through a second,
  independent channel;
- the operator and rollback decision owner;
- the database, private-storage, backup, TLS, worker, scheduler, and SMTP
  owners; and
- the client-approved RPO, RTO, retention, and legal-hold requirements.

Stop if any approval, fingerprint, package sidecar, recovery owner, or rollback
owner is missing.

## 2. Check the supported server baseline

The tested `1.0.0` matrix is:

| Component | Supported baseline |
| --- | --- |
| PHP | `8.4.24` or `8.5.9`; do not assume another branch is supported |
| Symfony | locked `7.4` LTS dependencies from the package |
| MariaDB | `10.11.19` or `11.8.9` |
| Web server | Nginx or Apache with PHP-FPM and a selectable document root |
| Worker | systemd or Supervisor preferred; bounded CLI cron is the fallback |

Install these PHP extensions: `ctype`, `curl`, `dom`, `filter`, `gd` with JPEG,
PNG and WebP, `hash`, `intl`, `json`, `mbstring`, `openssl`, `pdo`,
`pdo_mysql`, `session`, `sodium`, and `zip`.

Confirm the host before continuing:

```sh
php -v
php -m
php -r 'foreach (["curl","dom","gd","intl","mbstring","openssl","pdo_mysql","sodium","zip"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing extension: $e\n"); exit(1); } }'
mariadb --version
```

Also confirm time synchronization, at least twice the uncompressed package size
plus 512 MiB release reserve, enough database/private/backup capacity for the
client's campaign limits, and working TLS-certificate renewal. Do not continue
with an unsupported shared host whose document root cannot be set to
`current/public`.

## 3. Create the least-privilege service identity and paths

The examples use a non-login account named `emailer`, group `emailer`, and this
layout:

```text
/srv/emailer/releases/              root-controlled immutable releases
/var/lib/emailer/private/           writable application-private state
/var/lib/emailer/keys/              service-owned mode-0700 key/recovery boundary
/var/backups/emailer/               encrypted local backups
/etc/emailer/runtime.env            protected service environment
/etc/emailer/release-signing.pub     independently pinned release public key
/var/lib/emailer-candidate/          root-controlled candidate staging
```

Create them with the platform's account-management tools, then enforce the
equivalent of:

```sh
sudo useradd --system --home /var/lib/emailer --shell /usr/sbin/nologin emailer
sudo install -d -o root -g root -m 0755 /srv/emailer /srv/emailer/releases
sudo install -d -o emailer -g emailer -m 0700 /var/lib/emailer/private /var/lib/emailer/keys /var/backups/emailer
sudo install -d -o emailer -g emailer -m 0700 /var/lib/emailer/private/locks
sudo install -d -o root -g emailer -m 0750 /etc/emailer
sudo install -d -o root -g root -m 0755 /var/lib/emailer-candidate
```

If the account already exists, verify it instead of recreating it. Normal
PHP-FPM, worker, and scheduler processes run only as `emailer` and receive no
release-write identity. A root-controlled one-shot updater runs only
independently authenticated target-version code; it may read the service-owned
master key but cannot rotate or replace it. The web and worker processes may
write private/runtime paths, but never source, immutable release files, package
staging, the pinned public key, or service definitions. The release-signing
private key must never be copied to this server.

## 4. Create the MariaDB database and restricted account

Have the database operator create one UTF-8 database and one application
account restricted to the actual application host. Generate the database
password in the client's secret-management system. Enter it only through a
protected database administration session and protected runtime file; avoid
command-line arguments and shell history.

Equivalent SQL, with the placeholder replaced inside that protected session,
is:

```sql
CREATE DATABASE emailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'emailer'@'localhost' IDENTIFIED BY '<CLIENT-GENERATED-SECRET>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP,
      REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
ON emailer.* TO 'emailer'@'localhost';
FLUSH PRIVILEGES;
```

Use the real database host instead of `localhost` when necessary and enforce
network/TLS controls appropriate to that topology. Do not grant global,
administrative, file, replication, or user-management privileges.

## 5. Pin the release public key independently

Obtain the Ed25519 public key and its SHA-256 fingerprint through two separate
operator-controlled channels. Do not trust the convenience public key merely
because it arrived beside the archive.

Copy the independently verified key to the final path, then check it without
printing its contents:

```sh
sudo install -o root -g root -m 0644 /private/incoming/release-signing.pub /etc/emailer/release-signing.pub
sudo sha256sum /etc/emailer/release-signing.pub
sudo stat -c '%F %a %U:%G %h' /etc/emailer/release-signing.pub
```

The digest must equal the independently recorded fingerprint. The file must be
a regular, single-link file and not group/world writable. Stop on any mismatch.

## 6. Stage and verify every release file

Copy these files into `/var/lib/emailer-candidate` over the approved private
transfer channel:

- `emailer-next-1.0.0.zip`;
- `.manifest.json`, `.sha256`, `.spdx.json`, and `.sig` sidecars;
- `verify-package.php` and `PackageInspector.php`; and
- the release-candidate record and this manual.

The four sidecars have distinct jobs: the manifest defines the package/source/
schema contract and file inventory, the SHA-256 file detects archive
corruption, the SPDX document records the software bill of materials, and the
detached signature authenticates the package evidence under the pinned public
key. Keep them beside the ZIP; the verifier consumes them automatically and an
operator must not edit them.

Compare the two verifier-script SHA-256 values with the separately approved
release-candidate record. Then verify the archive checksum and the complete
signed package contract as effective user `emailer`:

```sh
sudo sha256sum /var/lib/emailer-candidate/verify-package.php /var/lib/emailer-candidate/PackageInspector.php
sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.0.zip.sha256'
sudo -u emailer php /var/lib/emailer-candidate/verify-package.php \
  --archive=/var/lib/emailer-candidate/emailer-next-1.0.0.zip \
  --public-key=/etc/emailer/release-signing.pub \
  --current-version=0.1.0 \
  --expected-version=1.0.0 \
  --expected-source=<APPROVED-40-CHARACTER-SOURCE-COMMIT>
```

For a clean install, `--current-version=0.1.0` selects the signed compatibility
floor only; it does not claim that a release is already installed. The verifier
checks the pinned signature, checksum, SBOM identity/licenses, semantic/source
identity, platform contract, archive paths and links, required runtime files,
and every file digest before extraction. Stop on any non-zero result.

## 7. Extract and freeze the immutable release

Only after Step 6 succeeds:

```sh
sudo install -d -o root -g root -m 0755 /srv/emailer/releases/1.0.0
sudo unzip -q /var/lib/emailer-candidate/emailer-next-1.0.0.zip -d /srv/emailer/releases/1.0.0
sudo chown -R root:root /srv/emailer/releases/1.0.0
sudo find /srv/emailer/releases/1.0.0 -type d -exec chmod 0555 {} +
sudo find /srv/emailer/releases/1.0.0 -type f -exec chmod 0444 {} +
```

Confirm that `bin/console`, `composer.lock`, `config/services.yaml`, and
`public/index.php` exist, and that `.git`, `.env`, tests, logs, backups, private
data, and any key do not. Do not run Composer on the client server; the verified
archive already contains the locked production dependency tree.

Create the initial pointer atomically:

```sh
sudo ln -s /srv/emailer/releases/1.0.0 /srv/emailer/releases/.current-1.0.0
sudo mv -T /srv/emailer/releases/.current-1.0.0 /srv/emailer/releases/current
```

## 8. Create protected runtime configuration

Create `/etc/emailer/runtime.env` as root, mode `0640`, group `emailer`. Use the
client secret manager/editor so values do not enter shell history. Supply all
of these keys with client-specific values:

```text
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<AT-LEAST-32-RANDOM-BYTES-ENCODED-SAFELY>
DEFAULT_URI=https://emailer.client.example
DATABASE_URL=mysql://emailer:<PERCENT-ENCODED-PASSWORD>@localhost:3306/emailer?serverVersion=10.11.19-MariaDB&charset=utf8mb4
EMAILER_PRIVATE_DIR=/var/lib/emailer/private
EMAILER_RUNTIME_DIR=/var/lib/emailer/private
EMAILER_SECRET_KEY_FILE=/var/lib/emailer/keys/master.key
EMAILER_RECOVERY_KEY_FILE=/var/lib/emailer/keys/recovery-key.enxkey
EMAILER_RELEASE_PUBLIC_KEY_FILE=/etc/emailer/release-signing.pub
EMAILER_RELEASES_DIR=/srv/emailer/releases
EMAILER_BACKUP_DESTINATION_DIR=
TRUSTED_PROXIES=127.0.0.1,::1
TRUSTED_HOSTS='^emailer\.client\.example$'
MAILER_DSN=smtp://127.0.0.1:1025
MESSENGER_TRANSPORT_DSN=doctrine://default
LOCK_DSN=flock:///var/lib/emailer/private/locks
```

The setup-only Mailer DSN must point to a private local Mailpit instance. Do not
put production SMTP credentials in `MAILER_DSN`; production SMTP is stored
encrypted later through the Owner interface. Replace the host expression and
trusted-proxy list exactly for the reviewed deployment. Wildcards and broad
private ranges are prohibited.

The explicit shared lock directory is mandatory. Bare `LOCK_DSN=flock` uses a
per-service temporary directory when `PrivateTmp=true` and would not coordinate
maintenance across PHP-FPM, workers, timers, and the updater. The single-quoted
`TRUSTED_HOSTS` value is also mandatory: systemd removes unquoted backslashes.

Verify metadata without displaying values:

```sh
sudo chown root:emailer /etc/emailer/runtime.env
sudo chmod 0640 /etc/emailer/runtime.env
sudo stat -c '%F %a %U:%G %h' /etc/emailer/runtime.env
```

For the remaining supervised CLI steps, keep one protected root terminal open
and define this launcher. It runs PHP as `emailer`, loads the protected file
through systemd (never through shell expansion), uses the immutable current
working directory, and removes the transient unit after each command:

```sh
emailer_cli() {
  sudo systemd-run --quiet --wait --collect --pty \
    --property=User=emailer \
    --property=Group=emailer \
    --property=WorkingDirectory=/srv/emailer/releases/current \
    --property=EnvironmentFile=/etc/emailer/runtime.env \
    --property=NoNewPrivileges=yes \
    --property=PrivateTmp=yes \
    --property=ProtectSystem=strict \
    --property='ReadWritePaths=/var/lib/emailer/private /var/lib/emailer/keys /var/backups/emailer' \
    /usr/bin/php /srv/emailer/releases/current/bin/console "$@"
}
```

Do not replace this with `source /etc/emailer/runtime.env`, `sudo -E`, or values
copied into command arguments. Stop if the host's systemd does not accept these
properties; define an equivalent reviewed service-account launcher before
continuing.

## 9. Configure PHP-FPM and the web server

Create a dedicated PHP-FPM pool running as `emailer`, with its working
directory set to `/srv/emailer/releases/current`, and import the protected
environment file using the platform's secret-safe service mechanism. Do not
paste secrets into a world-readable pool or web-server configuration.

Start from `deployment/nginx-emailer.conf.example` or
`deployment/apache-emailer-vhost.conf.example` in the release source. Replace
only the reviewed hostname, certificate, and PHP-FPM socket. Mandatory rules:

- document root is exactly `/srv/emailer/releases/current/public`;
- only `public/index.php` is passed to PHP;
- dotfiles, directory indexes, alternate PHP files, environment files, logs,
  dumps, backups, and keys are denied;
- HTTPS is enforced and the trusted proxy replaces, rather than appends,
  `X-Forwarded-For`; and
- the virtual host remains unavailable to untrusted traffic until setup and
  exposure checks pass.

Validate configuration with `nginx -t` or `apachectl configtest` before reload.

## 10. Apply migrations and run credential-free preflight

Load the runtime environment through the service manager; do not `source` it in
a shared or logged shell. As the `emailer` account, run:

```sh
emailer_cli doctrine:migrations:migrate --no-interaction
emailer_cli doctrine:migrations:status
emailer_cli emailer:setup:install
```

The target schema is `20260828000000`. Stop on a migration/preflight failure;
never mark a migration complete manually. The command does not print the DSN or
create a production SMTP connection.

## 11. Complete first-run setup from the protected terminal

The immutable client layout uses the CLI alternative so the web process never
needs write permission to a release directory. From a protected interactive
terminal as `emailer`, with the service manager supplying `runtime.env`, run:

```sh
emailer_cli emailer:setup:install --complete
```

Do not add values as command-line options or redirect the session to a log. The
installer uses hidden prompts for the database and Owner passwords and ordinary
prompts for non-secret values. Enter, in order:

1. the exact MariaDB host, port, database, restricted application user, and
   application password already represented by `DATABASE_URL`—not a MariaDB
   administrator/root password;
2. the organisation name, exact canonical HTTPS origin (scheme plus hostname,
   with no `/setup`, other path, query, or fragment), locale, and time zone;
3. an individual Owner email and password-manager-generated password;
4. only the private local Mailpit host/port (`127.0.0.1:1025` for the documented
   single-server layout) and a synthetic sender identity;
5. `supervised` or the reviewed `cron` worker profile; and
6. final confirmation after reviewing the summary.

The command verifies the external database configuration, creates the external
mode-`0600` master key, sends only the reserved Mailpit setup message, saves
bounded safe defaults, runs the critical setup checks, and publishes the
permanent private installation lock. It never prints or persists plaintext
input secrets. It deliberately does not display TOTP seed/recovery material.

After completion, sign in over HTTPS and immediately enrol TOTP MFA from the
Owner security page. Import its provisioning URI/seed into an authenticator
app, enter the current six-digit code generated by that app, and place the
one-time recovery codes in separate approved custody. Confirm every `/setup`
route returns an empty 404. Never delete the lock to reopen setup.

The filesystem-proof web wizard remains supported for non-immutable deployment
layouts described in [first-run-setup.md](first-run-setup.md), but it is not the
procedure for this root-owned immutable client layout.

## 12. Replace setup mail with encrypted production SMTP

After Owner MFA enrollment, re-authenticate for mail configuration, then
enter the approved SMTP hostname, STARTTLS or implicit TLS port/mode, username,
credential, sender identity, and optional reply-to. The credential is encrypted
under the external master key and is never redisplayed.

Leave RFC 8058 provider-DKIM attestation off unless the provider is confirmed
to DKIM-sign both `List-Unsubscribe` and `List-Unsubscribe-Post`. Saving SMTP
configuration does not contact the provider. A live provider test, credential
change, sender verification, or DNS change requires its own approved action.

## 13. Install worker, scheduler, and operations services

The signed archive includes the reviewed worker, scheduler, operations, and
update units in `deployment/`. Install fixed root-owned copies; never run a
service definition from the writable pointer itself:

```sh
sudo install -o root -g root -m 0644 /srv/emailer/releases/current/deployment/emailer-worker.service.example /etc/systemd/system/emailer-worker.service
sudo install -o root -g root -m 0644 /srv/emailer/releases/current/deployment/emailer-scheduler.service.example /etc/systemd/system/emailer-scheduler.service
sudo install -o root -g root -m 0644 /srv/emailer/releases/current/deployment/emailer-scheduler.timer.example /etc/systemd/system/emailer-scheduler.timer
sudo install -o root -g root -m 0644 /srv/emailer/releases/current/deployment/emailer-operations.service.example /etc/systemd/system/emailer-operations.service
sudo install -o root -g root -m 0644 /srv/emailer/releases/current/deployment/emailer-operations.timer.example /etc/systemd/system/emailer-operations.timer
sudo install -o root -g root -m 0644 /srv/emailer/releases/current/deployment/emailer-update@.service.example /etc/systemd/system/emailer-update@.service
sudo systemctl daemon-reload
sudo systemctl enable --now emailer-worker.service emailer-scheduler.timer emailer-operations.timer
```

Review every copied path and sandbox control before starting it. Run all
ordinary processes as `emailer`, from `/srv/emailer/releases/current`, with the
same protected runtime environment. The inactive update template is root-only,
loads a separately recorded source version, and executes only a verified,
root-owned target tree under `/var/lib/emailer-candidate/verified-<version>`.
It also requires the exact retained signed predecessor package and original
sidecars described in [CLIENT-UPDATE-1.0.0.md](CLIENT-UPDATE-1.0.0.md); the
source-version record alone cannot start a valid update.
Its cache and logs use a dedicated root-only systemd `RuntimeDirectory`; they
never change ownership of the ordinary service cache.

Required recurring processes are:

```sh
php bin/console messenger:consume async --keepalive=5 --time-limit=3600 --memory-limit=256M --failure-limit=10
php bin/console emailer:scheduler:run
php bin/console emailer:operations:run
```

The timers invoke scheduler and operations at least once per minute. If systemd
is unavailable, translate these exact commands into bounded, non-overlapping
Supervisor/cron jobs; HTTP/URL cron is prohibited. Inspect redacted status only
and confirm fresh heartbeats.

## 14. Create recovery material and the first verified backup

Before any production campaign, export the master-key recovery package
interactively:

```sh
emailer_cli emailer:secrets:export-recovery-key
```

Store its passphrase separately from the package, master key, database, and
ordinary backup. Then create and verify an encrypted backup:

```sh
emailer_cli emailer:backup:create
emailer_cli emailer:backup:verify /private/path/to/generated-backup.enxbackup
```

The command reports a private artifact reference; do not copy that path into a
ticket. Perform an isolated restore drill before go-live and record only the
redacted result. External backup automation remains off until the Owner
approves a separately provisioned restrictive mount and policy.

Use `emailer:backup:restore <backup> --drill-receipt` only in the isolated drill
runtime. Transfer its mode-`0600` authenticated receipt into the originating
installation's private backup directory, then run interactive
`emailer:backup:record-restore-drill <receipt>` and a full Doctor. The recorder
checks the same key and exact retained backup; it records the drill's original
time without restoring live data. Follow [backup-restore.md](backup-restore.md)
for isolation, comparison, transfer, and cleanup boundaries. An old unsigned
operator diagnostic cannot clear this warning.

## 15. Run acceptance and exposure checks

Run:

```sh
emailer_cli emailer:release:check
emailer_cli emailer:doctor:run --json
curl --fail --silent https://emailer.client.example/health/live
```

The liveness body must be exactly `{"status":"ok"}`. From a host outside the
server, verify that representative forbidden paths such as `/.git/HEAD`,
`/.env`, `/composer.json`, `/var/log/`, `/backups/`, and `/master.key` return
404 without content. Confirm version `1.0.0`, schema `20260828000000`, correct
release pointer, TLS/host/proxy policy, worker/scheduler heartbeats, current
backup/recovery evidence, and no critical Doctor finding.

Also complete the browser/keyboard/assistive-technology checklist in
`docs/accessibility-browser-support.md`. Automated checks do not replace that
human acceptance.

## 16. Go-live decision

Keep delivery quiesced until all of these are true:

- package/source/key identities match the approved record;
- release source is immutable and not web-writable;
- setup is permanently locked;
- Owner MFA and recovery custody are confirmed;
- migrations, Doctor, worker, scheduler, backup verification, and restore drill
  pass;
- suppression integrity is healthy;
- client retention, backup, legal, SMTP, sender, DNS, and rate policies are
  approved; and
- the rollback owner explicitly accepts the baseline backup and recovery plan.

Only then run the separately authorized production-provider test and explicit
delivery resume. “Accepted” means transport acceptance, not inbox delivery.

## 17. Failed installation and rollback

Before production data or sending exists, a failed clean install is recovered
by keeping the virtual host unavailable, stopping Emailer services, preserving
redacted evidence, and correcting the failed step. Do not create a new master
key over encrypted records, manually edit migration metadata, delete the setup
lock, or repoint to an unverified directory.

After setup/data exists, use the verified encrypted backup and documented CLI
restore procedure. Keep delivery quiesced through every failure. If package,
signature, public-key fingerprint, private/public path, suppression, key,
backup, or restore integrity is uncertain, stop and follow
`docs/incident-response.md`.

## 18. Post-install handover

Give the client operator these records without secret values:

- installed version, schema, source commit, archive digest, and public-key
  fingerprint;
- service names and chosen worker profile;
- Doctor result and exposure-test result;
- backup/recovery/restore-drill status and owners;
- retention and maintenance schedule;
- supported-browser/accessibility acceptance record;
- approved SMTP/DNS/provider actions still pending; and
- rollback, incident, and escalation contacts stored in the client's protected
  operations system.

Remove candidate staging only after the approved archive/sidecars and handover
record exist in protected release custody. Never remove the active immutable
release or its external state as cleanup.
