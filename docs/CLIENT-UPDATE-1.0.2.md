# Update Emailer Next 1.0.0 to 1.0.2

The `1.0.2` package directly supports exactly `1.0.0`. An unlisted patch, prerelease, or development version is not supported. The target schema is `20261008000000`; the minimum source schema is `20260828000000`.

The migration adds optional campaign pacing, durable rate counters and draft-revision binding for campaign tests. MariaDB additive DDL may remain after an interrupted update and data restore; the exact migration is repeatable so a reviewed retry can complete those additions. Existing campaigns keep the installation rate. Editing a draft or changing RSVP invalidates prior tests.

## Before updating

1. Confirm the installed source version is exactly `1.0.0`, the
   immutable current pointer is valid, Doctor has no unexplained critical
   finding, and no campaign is actively sending.
2. Create and verify an encrypted backup. Confirm the separately protected
   recovery package/passphrase and pinned release public key are available.
3. Record the current version/schema/pointer, active-claim count, the exact
   predecessor package source commit and archive SHA-256, the approved `1.0.2`
   source commit and archive SHA-256, and the rollback owner. The predecessor
   values come from its retained accepted candidate record, not from the
   directory name. Capture and retain the complete schema/ledger checkpoint
   described below before migration; verify both recorded hashes.
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
   the `1.0.2` archive and all four of its sidecars, `verify-package.php`,
   `PackageInspector.php`, and both release-candidate records outside the web
   root. Install them as `root:root` mode `0644` under
   `/var/lib/emailer-candidate/`. Do not reconstruct a predecessor manifest or
   accept only its source checkout. Compare both verifier hashes with the
   separately approved `1.0.2` candidate record, verify both checksums, and then
   verify both complete signed package contracts against the independently
   pinned key:

   ```sh
   sudo sha256sum /var/lib/emailer-candidate/verify-package.php /var/lib/emailer-candidate/PackageInspector.php
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-<EXACT-RECORDED-SOURCE-VERSION>.zip.sha256'
   sudo -u emailer php /var/lib/emailer-candidate/verify-package.php \
     --archive=/var/lib/emailer-candidate/emailer-next-<EXACT-RECORDED-SOURCE-VERSION>.zip \
     --public-key=/etc/emailer/release-signing.pub \
     --current-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-source=<EXACT-RECORDED-SOURCE-COMMIT> \
     --installed-source
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.2.zip.sha256'
   sudo -u emailer php /var/lib/emailer-candidate/verify-package.php \
     --archive=/var/lib/emailer-candidate/emailer-next-1.0.2.zip \
     --public-key=/etc/emailer/release-signing.pub \
     --current-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-version=1.0.2 \
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
   sudo install -d -o root -g root -m 0755 /var/lib/emailer-candidate/verified-1.0.2
   sudo unzip -q /var/lib/emailer-candidate/emailer-next-1.0.2.zip -d /var/lib/emailer-candidate/verified-1.0.2
   sudo chown -R root:root /var/lib/emailer-candidate/verified-1.0.2
   sudo find /var/lib/emailer-candidate/verified-1.0.2 -type d -exec chmod 0555 {} +
   sudo find /var/lib/emailer-candidate/verified-1.0.2 -type f -exec chmod 0444 {} +
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.2.zip.sha256'
   ```

Stop on any mismatch, unsupported source/schema/platform result, critical
Doctor finding, missing backup/recovery evidence, or active delivery that
cannot quiesce.

## Run the supervised update

Install the authenticated target tree's
`deployment/emailer-update@.service.example` as root-owned
`/etc/systemd/system/emailer-update@.service`. Review it against the client
installation record. Create `/etc/emailer/update.env` as `root:root` mode
`0600` containing only the exact recorded predecessor, for example
`EMAILER_UPDATE_SOURCE_VERSION=1.0.0`. Verify that the basename resolved by
`/srv/emailer/releases/current` is exactly the same version. The target updater
rejects a mismatch. Reload systemd only after these checks.

```sh
sudo install -o root -g root -m 0644 /var/lib/emailer-candidate/verified-1.0.2/deployment/emailer-update@.service.example /etc/systemd/system/emailer-update@.service
sudo install -o root -g root -m 0600 /dev/null /etc/emailer/update.env
sudoedit /etc/emailer/update.env
readlink -f /srv/emailer/releases/current
sudo systemctl daemon-reload
```

Do not put the source-version override in `runtime.env`; ordinary application
processes must continue to identify themselves as their packaged version.
The unit overrides only `EMAILER_RUNTIME_DIR` to its root-only transient
`/run/emailer-update-1.0.2` directory, preventing target container cache files
from colliding with or changing ownership of the ordinary service cache. Its
`ExecStart` applies that override through `/usr/bin/env` after systemd has read
both protected environment files: `EnvironmentFile` would override a plain
`Environment=EMAILER_RUNTIME_DIR=...` assignment.

Run the supervised update through the root-controlled unit:

```sh
sudo systemctl start 'emailer-update@''1.0.2.service'
sudo systemctl status 'emailer-update@''1.0.2.service' --no-pager
```

The root-controlled service executes the authenticated `1.0.2` target code,
using a separate runtime cache directory. The target updater
re-verifies the target and predecessor pinned signatures, manifests, checksums,
SBOMs, exact recorded source/current-pointer identity, and every installed
predecessor file before mutation. It then verifies schema compatibility, paths,
and disk reserve, and locks
maintenance, quiesces/reconciles delivery, creates and verifies a safety backup,
returns that mode-`0600` encrypted file to the owner/group of its service-owned
backup directory, installs recursively root-owned immutable `1.0.2`, runs every
pending migration, switches the pointer
atomically, and runs the maintenance Doctor.

The newsletter pacing package conservatively declares
`rollback_schema_compatible=false`. On failure, keep delivery quiesced and
follow the recorded verified-backup restore outcome. Never point older code at
a database that may have advanced.

## Restart and verify

Install the reviewed worker, scheduler, operations, and update unit examples
from the new immutable release as the fixed root-owned files named in Step 13
of `CLIENT-INSTALL-1.0.2.md`, then run `systemctl daemon-reload`. This is
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

Confirm version `1.0.2`, schema `20261008000000`, the expected current pointer,
fresh heartbeats, backup/recovery status, suppression integrity, and no critical
Doctor finding. Review the `1.0.2` license/browser/accessibility support record
and the new installation manual with the client operator.

Resume delivery only through the documented `emailer:delivery:resume` command
after the change owner accepts all evidence. Publication, deployment, provider
contact, DNS change, credential rotation, and a live SMTP test remain separate
operator actions.

## Required schema checkpoint before migration

Before running the updater, capture a schema-only dump with no recipient or
credential rows and the full ordered `doctrine_migration_versions` rows
(`version`, `executed_at`, `execution_time`, ordered by `version`). Normalize
only table-level ` AUTO_INCREMENT=N` counters, as the updater does. Retain the
complete table definitions, ledger and SHA-256 of each in a root-only operation
directory alongside the verified encrypted backup and exact source path.
Confirm that the checkpoint is readable and hashes verify before migration.
Recovery step 1 compares against these exact retained artifacts; a latest
version number alone cannot establish source schema recovery.

## Schema recovery guard

The encrypted application backup restores business rows and assets; it does not
recreate MariaDB table definitions or migration metadata. The updater fingerprints
both before migration and verifies them after a failed-update data restore. If
either differs, recovery records `rollback_failed`, retains the target when it
has already become current, and keeps delivery quiesced. It refuses to switch
older code back onto that advanced schema. Complete supervised database schema
and migration-ledger recovery before attempting an older-release rollback; a
successful row restore alone is insufficient. Do not resume delivery following
this failure. Repeatable additive migrations permit a reviewed forward retry
after exact source recovery; they are not an automatic DDL downgrade.

### Supervised recovery and forward retry

The change owner must retain the pre-update schema/ledger fingerprint and exact
source path alongside the verified encrypted backup. A failed migration may
leave `current` on `1.0.0`; that old Doctor does not implement the new recovery
finding. Check the latest `release_operation.detail_code` through protected
operator tooling. `rollback_failed` forbids resuming delivery even if the old
Doctor displays no other critical finding.

1. Keep delivery quiesced. Recover **both** the complete source table definitions
   and migration ledger, then business data/assets from the verified backup.
   Compare the recovered normalized table definitions and full ordered ledger
   with the retained pre-update checkpoint and verify both SHA-256 values, plus
   preserved business-data fingerprints.
   Do not delete a migration row to conceal an advanced table definition.
2. Only after source recovery is verified, atomically repoint `current` to the
   exact recorded immutable `1.0.0` directory. Use a temporary sibling symlink
   and atomic rename; verify its resolved path and signed predecessor files.
3. If retained, move the exact recorded `<RELEASES-DIRECTORY>/1.0.2` directory
   to a protected recovery quarantine outside the web root. Confirm `current`
   resolves to the verified source first. Use an exact path, no wildcard, and
   never operate through `current`. This removes the failed target from the
   install location while retaining evidence; `install()` rejects an existing
   target directory on retry.
4. Clear/warm the ordinary service cache as `emailer` using the recovered source,
   start its quiesced worker and refresh scheduler heartbeats. Stop periodic
   operations while retaining worker heartbeat through supervised verification.
   Re-authenticate the unchanged source and reviewed target packages.
5. Rerun the `emailer-update@` instance for version `1.0.2` with the exact reviewed target. Its
   transient root cache must be fresh. The recovery finding clears only after a
   subsequent verified update passes and records success. Restart on new code,
   verify preservation/readiness, then explicitly resume delivery.

If complete source recovery cannot be verified, retain the installed target,
backup and failure evidence and obtain supervised database recovery. Do not
force the pointer, remove the target, or clear the failure record merely to
make Doctor appear healthy.
