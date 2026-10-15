# Update Emailer Next 1.0.5 to 1.0.6

The `1.0.6` native update accepts exactly Ripe `1.0.5`. It adds campaign-owned
recipient compatibility alongside reusable contacts, explicit cryptographic
context metadata and a unique global suppression identity that retains valid
historical case aliases. It adds no dependency. Existing security-level settings,
roles, configured MFA and list workflows retain their behavior.

The target schema is `20261015000000`, from `20261013000000`. Pointer rollback
after migration is prohibited; retain the complete protected schema/ledger
checkpoint and verified backup for recovery. Light uses the separate edition
installer documented in CLIENT-LIGHT-UPGRADE-1.0.6.md.

## Before updating

The native updater requires a dedicated application database account and a
separate privileged maintenance identity for the same database/server. Supply
the original protected runtime configuration through
`EMAILER_RUNTIME_CONFIGURATION_FILE`. Quiesce HTTP, workers and schedulers.
The updater persistently locks the application account and terminates its
existing connections after claims are idle, before copying database state.
This blocks recipient HTTP writes as well as delivery, including across a crash.
Successful update/recovery restores the original account lock state only after
validation; delivery remains separately held. A failed proof retains the fence.


1. Confirm the installed source version is exactly `1.0.5`, the
   immutable current pointer is valid, Doctor has no unexplained critical
   finding, and no campaign is actively sending.
   Select the supported CLI binary used by the active Emailer services, for
   example `PHP_CLI=/usr/bin/php8.5`, and verify it with `test -x "$PHP_CLI"`,
   `"$PHP_CLI" -v` and `"$PHP_CLI" -m`. Keep that value in the protected
   terminal. Do not rely on the host's default `/usr/bin/php` symlink.
2. Create and verify an encrypted backup. Confirm the separately protected
   recovery package/passphrase and pinned release public key are available.
3. Record the current version/schema/pointer, active-claim count, the exact
   predecessor package source commit and archive SHA-256, the approved `1.0.6`
   source commit and archive SHA-256, and the rollback owner. The predecessor
   values come from its retained accepted candidate record, not from the
   directory name. Capture and retain the complete schema/ledger checkpoint
   described below before update; verify both recorded hashes.
4. Normalize the recorded releases root to `root:root` mode `0755`, keep every
   existing semantic-version directory root-owned and frozen, and create
   candidate staging as `root:root` mode `0755`. Do not recursively cross the `current`
   symlink or change external private/key/backup ownership. Equivalent commands,
   after replacing the placeholder with the exact recorded path, are:

   ```sh
   sudo chown root:root <RELEASES-DIRECTORY>
   sudo chmod 0755 <RELEASES-DIRECTORY>
   test -d <CURRENT-RELEASE-DIRECTORY> && test ! -L <CURRENT-RELEASE-DIRECTORY>
   sudo chown -R root:root <CURRENT-RELEASE-DIRECTORY>
   sudo find <CURRENT-RELEASE-DIRECTORY> -type d -exec chmod 0555 {} +
   sudo find <CURRENT-RELEASE-DIRECTORY> -type f -exec chmod 0444 {} +
   sudo install -d -o root -g root -m 0755 /var/lib/emailer-candidate
   ```

   Stop unless each existing release is recursively root-owned and non-writable
   and an ordinary `emailer` process cannot create, chmod, or replace anything
   in the releases root. Repeat the three ownership/mode commands with an exact,
   separately recorded path for every retained semantic-version rollback
   directory; never use a wildcard and never operate through `current`.
5. Stage the exact predecessor archive and all four of its original sidecars,
   the `1.0.6` archive and all four of its sidecars, `verify-package.php`,
   `PackageInspector.php`, and both release-candidate records outside the web
   root. Install them as `root:root` mode `0644` under
   `/var/lib/emailer-candidate/`. Do not reconstruct a predecessor manifest or
   accept only its source checkout. Compare both verifier hashes with the
   separately approved `1.0.6` candidate record, verify both checksums, and then
   verify both complete signed package contracts against the independently
   pinned key:

   ```sh
   sudo sha256sum /var/lib/emailer-candidate/verify-package.php /var/lib/emailer-candidate/PackageInspector.php
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-<EXACT-RECORDED-SOURCE-VERSION>.zip.sha256'
   sudo -u emailer "$PHP_CLI" /var/lib/emailer-candidate/verify-package.php \
     --archive=/var/lib/emailer-candidate/emailer-next-<EXACT-RECORDED-SOURCE-VERSION>.zip \
     --public-key=/etc/emailer/release-signing.pub \
     --current-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-source=<EXACT-RECORDED-SOURCE-COMMIT> \
     --installed-source
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.6.zip.sha256'
   sudo -u emailer "$PHP_CLI" /var/lib/emailer-candidate/verify-package.php \
     --archive=/var/lib/emailer-candidate/emailer-next-1.0.6.zip \
     --public-key=/etc/emailer/release-signing.pub \
     --current-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-version=1.0.6 \
     --expected-source=<APPROVED-40-CHARACTER-SOURCE-COMMIT>
   ```

   The two script digests must match the independently approved record and both
   verifier runs must exit zero. A co-delivered checksum without the pinned
   signature is not authentication. Stop if the predecessor package or any
   original sidecar is unavailable; a matching version string is insufficient.
6. Only after Step 5 succeeds, extract the already authenticated archive into
   a root-owned, non-writable target-code directory and recheck the archive
   checksum before installing anything from it:

   ```sh
   sudo install -d -o root -g root -m 0755 /var/lib/emailer-candidate/verified-1.0.6
   sudo unzip -q /var/lib/emailer-candidate/emailer-next-1.0.6.zip -d /var/lib/emailer-candidate/verified-1.0.6
   sudo chown -R root:root /var/lib/emailer-candidate/verified-1.0.6
   sudo find /var/lib/emailer-candidate/verified-1.0.6 -type d -exec chmod 0555 {} +
   sudo find /var/lib/emailer-candidate/verified-1.0.6 -type f -exec chmod 0444 {} +
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.6.zip.sha256'
   ```

Stop on any mismatch, unsupported source/schema/platform result, critical
Doctor finding, missing backup/recovery evidence, or active delivery that
cannot quiesce.

## Run the supervised update

Install the authenticated target tree's
`deployment/emailer-update@.service.example` as root-owned
`/etc/systemd/system/emailer-update@.service`. Review it against the client
installation record. Create `/etc/emailer/update.env` as `root:root` mode
`0600` containing `EMAILER_UPDATE_SOURCE_VERSION=1.0.5`,
`EMAILER_RUNTIME_CONFIGURATION_FILE=/etc/emailer/runtime.env`, and a `DATABASE_URL`
override for the separate privileged maintenance identity. Keep that URL only in
this protected file; do not print it or place it in command arguments. The
maintenance identity needs complete DDL/data/trigger access to this installation,
`CREATE USER` authority for account locking, `CONNECTION ADMIN` for terminating
the dedicated application sessions, and read access to `mysql.global_priv`.
The application account must retain only its installation-scoped grants. Verify that the basename resolved by
`/srv/emailer/releases/current` is exactly the same version. The target updater
rejects a mismatch. Reload systemd only after these checks.

```sh
sudo install -o root -g root -m 0644 /var/lib/emailer-candidate/verified-1.0.6/deployment/emailer-update@.service.example /etc/systemd/system/emailer-update@.service
sudo sed -i "s#/usr/bin/php #${PHP_CLI} #g" /etc/systemd/system/emailer-update@.service
sudo grep '^ExecStart=' /etc/systemd/system/emailer-update@.service
sudo install -d -o root -g root -m 0700 /var/lib/emailer/native-update-recovery
sudo install -o root -g root -m 0600 /dev/null /etc/emailer/update.env
sudoedit /etc/emailer/update.env
readlink -f /srv/emailer/releases/current
sudo systemctl daemon-reload
```

Stop unless the reviewed updater `ExecStart` uses the selected supported
`PHP_CLI` binary. The packaged unit is an example and may contain an
`/usr/bin/php` path that resolves to an unsupported host default.

Do not put the source-version override in `runtime.env`; ordinary application
processes must continue to identify themselves as their packaged version.
The unit overrides only `EMAILER_RUNTIME_DIR` to its root-only transient
`/run/emailer-update-1.0.6` directory, preventing target container cache files
from colliding with or changing ownership of the ordinary service cache. Its
`ExecStart` applies that override through `/usr/bin/env` after systemd has read
both protected environment files: `EnvironmentFile` would override a plain
`Environment=EMAILER_RUNTIME_DIR=...` assignment.

Stop the recorded PHP-FPM/HTTP application processes, workers, schedulers and
operations timers before starting the updater, and keep them stopped until the
new current code is warmed and verified. The account fence covers database
writers during checkpointing; it does not replace stopping stale application
processes before that fence is released after validation.

The reviewed unit grants the root-only updater write access to the recovery
root and the protected key/configuration directories because complete recovery
uses durable atomic file replacement there. The candidate and pinned signing
public key remain read-only; `ProtectSystem=strict`, `NoNewPrivileges` and
root/service ownership boundaries remain enforced. Review every path against
the recorded installation, including custom key/configuration locations.

Run the supervised update through the root-controlled unit:

```sh
sudo systemctl start 'emailer-update@''1.0.6.service'
sudo systemctl status 'emailer-update@''1.0.6.service' --no-pager
```

The root-controlled service executes the authenticated `1.0.6` target code,
using a separate runtime cache directory. The target updater
re-verifies the target and predecessor pinned signatures, manifests, checksums,
SBOMs, exact recorded source/current-pointer identity, and every installed
predecessor file before mutation. It then verifies schema compatibility, paths,
and disk reserve, and locks
maintenance, quiesces/reconciles delivery, creates and verifies a safety backup,
returns that mode-`0600` encrypted file to the owner/group of its service-owned
backup directory, installs recursively root-owned immutable `1.0.6`, runs every
pending migration, switches the pointer
atomically, and runs the maintenance Doctor.

The security-level update declares
`rollback_schema_compatible=false` because it adds a migration. On failure,
follow the updater's verified source, schema, backup and Doctor checks before
any recovery or delivery resume. Never point 1.0.5 code at the 1.0.6 schema; restore the exact 1.0.5 schema and migration ledger from the protected checkpoint before predecessor recovery.

## Restart and verify

Install the reviewed worker, scheduler, operations, and update unit examples
from the new immutable release as the fixed root-owned files named in Step 13
of `CLIENT-INSTALL-1.0.6.md`, substitute the same verified `PHP_CLI` in all
four service `ExecStart` lines and inspect them, then run
`systemctl daemon-reload`. This is
required even when updating an older release that did not yet have every unit.
Before restarting PHP-FPM or workers, clear and warm the ordinary service cache
using the new current release as the `emailer` identity. The updater uses a
separate root cache and does not clear the ordinary service cache automatically.
Then restart PHP-FPM, enable/restart the worker and both timers, and confirm no
process retains old code.

In a protected root terminal, recreate the exact `emailer_cli` transient-unit
launcher from Step 8 of the installation manual, then run:

```sh
emailer_cli cache:clear
emailer_cli emailer:release:check
emailer_cli emailer:doctor:run --json
sudo systemctl start emailer-operations.service
```

Confirm version `1.0.6`, schema `20261015000000`, the expected current pointer,
fresh heartbeats, backup/recovery status, suppression integrity, and no critical
Doctor finding. Review the `1.0.6` license/browser/accessibility support record
and the new installation manual with the client operator.

Resume delivery only through the documented `emailer:delivery:resume` command
after the change owner accepts all evidence. Publication, deployment, provider
contact, DNS change, credential rotation, and a live SMTP test remain separate
operator actions.

## Required schema checkpoint before update

Before running the updater, capture a schema-only dump with no recipient or
credential rows and the full ordered `doctrine_migration_versions` rows
(`version`, `executed_at`, `execution_time`, ordered by `version`). Normalize
only table-level ` AUTO_INCREMENT=N` counters, as the updater does. Retain the
complete table definitions, ledger and SHA-256 of each in a root-only operation
directory alongside the verified encrypted backup and exact source path.
Confirm that the checkpoint is readable and hashes verify before update.
Recovery step 1 compares against these exact retained artifacts; a latest
version number alone cannot establish source schema recovery.

## Complete native update recovery

For the 1.0.5 to 1.0.6 update, the target updater creates and verifies a complete
protected checkpoint after quiescence and its normal encrypted safety backup.
The checkpoint is retained at the private directory's sibling
`native-update-recovery/<OPERATION-ID>`. It contains source DDL, migration ledger,
rows, private state and master key; when the original runtime configuration path
is supplied with `EMAILER_RUNTIME_CONFIGURATION_FILE`, that configuration and
its external recovery-key package are included as well. Retain this directory
and its separately protected recovery key.

If migration or post-activation validation fails, the updater restores that
complete checkpoint, verifies the original schema and ledger fingerprint,
restores the original immutable pointer, records failure and keeps delivery
quiesced. The actual packaged updater has passed a post-activation missing-key
fault rehearsal with exact source schema, historical rows, key and pointer
restored and no emitted messages. An older data-only restore cannot reverse
these DDL changes.

## Checked native interruption recovery

Keep source/target workers, schedulers and HTTP processes stopped or quiesced.
Retain both authenticated packages, all sidecars, the pinned Ripe key and the
original protected updater configuration with `EMAILER_UPDATE_SOURCE_VERSION=1.0.5`.
Use the operation UUID recorded in the protected
`native-update-recovery/<OPERATION-ID>/native-operation.json`; verify its target
archive hash and installation against the original change record.

```sh
sudo systemd-run --unit=emailer-native-recover --wait --collect --pipe \
  --property=User=root --property=Group=root \
  --property='EnvironmentFile=/etc/emailer/runtime.env /etc/emailer/update.env' \
  --property=RuntimeDirectory=emailer-native-recover \
  --property=RuntimeDirectoryMode=0700 \
  --property=NoNewPrivileges=true --property=PrivateTmp=true \
  --property=ProtectSystem=strict \
  --property='ReadWritePaths=/srv/emailer/releases /var/lib/emailer/private /var/backups/emailer /run/emailer-native-recover /var/lib/emailer/native-update-recovery /var/lib/emailer/keys /etc/emailer' \
  --property='ReadOnlyPaths=/var/lib/emailer-candidate /etc/emailer/release-signing.pub' \
  /usr/bin/env EMAILER_RUNTIME_DIR=/run/emailer-native-recover \
  "$PHP_CLI" /var/lib/emailer-candidate/verified-1.0.6/bin/console \
  emailer:release:update /var/lib/emailer-candidate/emailer-next-1.0.6.zip \
  --source-package=/var/lib/emailer-candidate/emailer-next-1.0.5.zip \
  --recover-native-operation=<EXACT-OPERATION-UUID>
```

The same root-controlled target launcher obtains explicit terminal confirmation.
For an already approved non-interactive isolated workflow, the existing
`--approved-target=1.0.6 --no-interaction` binding also applies. No SQL edits or
operation-row deletion are required.

A durable `preparing` journal precedes even the operation row and backup, so an interruption before a
checkpoint exists is recoverable, including a missing operation row when the latest historical update agrees with the protected predecessor identity. The command proves the original immutable
source package, database binding, schema/ledger, installation UUID and master
key; it does not restore data that the updater never mutated. It append-closes
the failed operation as `interrupted_before_mutation` only after source boot,
mapping and maintenance Doctor validation.

Once a verified checkpoint exists, recovery authenticates it, restores complete
DDL/data/private state, compares every table's keyed original-row fingerprint,
checks schema/ledger, keys and foreign keys, restores the original pointer and
validates the unchanged source before closing the operation as verified recovery.
A persistent `recovering` decision supports another interruption during restore.
Active claims are refused and never reset. Delivery remains quiesced; failures
retain protected diagnostics and cannot be hidden by another update or resume.

After verified recovery, fix the original cause and rerun the same authenticated
target update normally. Recovery is idempotent and retains its checkpoint.
Successful completed updates are explicitly rejected by this action; it is not
a general downgrade. Application-backup restoration preserves authoritative live
release-operation completion/recovery evidence over older snapshot states, with
an explicit audit event for that control-history overlay.

If a proof cannot complete, keep all processes and delivery quiesced and repair
the stated protected diagnostic before retrying this command. Do not clear an
operation or migration row merely to suppress a refusal. The exact operator-signed
predecessor archive and source record remain required. No production installation
was inspected by this engineering job.
