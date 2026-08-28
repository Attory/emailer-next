# Update Emailer Next 0.1.0, 0.2.0, 0.3.0, or 0.4.0 to 1.0.0

The `1.0.0` package directly supports exactly `0.1.0`, `0.2.0`, `0.3.0`, and
`0.4.0`. An unlisted patch, prerelease, or development version is not supported.
The target schema is `20260828000000`; the minimum source schema is
`20260824010000`.

## Before updating

1. Confirm the installed source version is one of those four exact versions, the
   immutable current pointer is valid, Doctor has no unexplained critical
   finding, and no campaign is actively sending.
2. Create and verify an encrypted backup. Confirm the separately protected
   recovery package/passphrase and pinned release public key are available.
3. Record the current version/schema/pointer, active-claim count, approved
   `1.0.0` source commit, archive SHA-256, and rollback owner.
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
5. Stage the archive, all four sidecars, `verify-package.php`,
   `PackageInspector.php`, and the release-candidate record outside the web
   root. Install them as `root:root` mode `0644` under
   `/var/lib/emailer-candidate/`. Compare both verifier hashes with the
   separately approved candidate record, verify the checksum, and then verify
   the complete signed package contract against the independently pinned key:

   ```sh
   sudo sha256sum /var/lib/emailer-candidate/verify-package.php /var/lib/emailer-candidate/PackageInspector.php
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.0.zip.sha256'
   sudo -u emailer php /var/lib/emailer-candidate/verify-package.php \
     --archive=/var/lib/emailer-candidate/emailer-next-1.0.0.zip \
     --public-key=/etc/emailer/release-signing.pub \
     --current-version=<EXACT-RECORDED-SOURCE-VERSION> \
     --expected-version=1.0.0 \
     --expected-source=<APPROVED-40-CHARACTER-SOURCE-COMMIT>
   ```

   The two script digests must match the independently approved record and the
   verifier must exit zero. A co-delivered checksum without the pinned
   signature is not authentication.
6. Only after Step 5 succeeds, extract the already authenticated archive into
   a root-owned, non-writable target-code directory and recheck the archive
   checksum before installing anything from it:

   ```sh
   sudo install -d -o root -g root -m 0755 /var/lib/emailer-candidate/verified-1.0.0
   sudo unzip -q /var/lib/emailer-candidate/emailer-next-1.0.0.zip -d /var/lib/emailer-candidate/verified-1.0.0
   sudo chown -R root:root /var/lib/emailer-candidate/verified-1.0.0
   sudo find /var/lib/emailer-candidate/verified-1.0.0 -type d -exec chmod 0555 {} +
   sudo find /var/lib/emailer-candidate/verified-1.0.0 -type f -exec chmod 0444 {} +
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.0.zip.sha256'
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
`EMAILER_UPDATE_SOURCE_VERSION=0.1.0`. Verify that the basename resolved by
`/srv/emailer/releases/current` is exactly the same version. The target updater
rejects a mismatch. Reload systemd only after these checks.

```sh
sudo install -o root -g root -m 0644 /var/lib/emailer-candidate/verified-1.0.0/deployment/emailer-update@.service.example /etc/systemd/system/emailer-update@.service
sudo install -o root -g root -m 0600 /dev/null /etc/emailer/update.env
sudoedit /etc/emailer/update.env
readlink -f /srv/emailer/releases/current
sudo systemctl daemon-reload
```

Do not put the source-version override in `runtime.env`; ordinary application
processes must continue to identify themselves as their packaged version.
The unit overrides only `EMAILER_RUNTIME_DIR` to its root-only transient
`/run/emailer-update-1.0.0` directory, preventing target container cache files
from colliding with or changing ownership of the ordinary service cache.

Run the supervised update through the root-controlled unit:

```sh
sudo systemctl start 'emailer-update@''1.0.0.service'
sudo systemctl status 'emailer-update@''1.0.0.service' --no-pager
```

The root-controlled service executes the authenticated `1.0.0` target code,
not the predecessor updater. This is required because accepted predecessor
binaries predate the combined release-train contract. The target updater
re-verifies the pinned signature, manifest, checksum, SBOM, exact recorded
source/current-pointer identity, schema compatibility, paths, and disk reserve
before mutation. It then locks
maintenance, quiesces/reconciles delivery, creates and verifies a safety backup,
returns that mode-`0600` encrypted file to the owner/group of its service-owned
backup directory, installs recursively root-owned immutable `1.0.0`, runs every
pending migration, switches the pointer
atomically, and runs the maintenance Doctor.

The combined release-train package conservatively declares
`rollback_schema_compatible=false`. On failure, keep delivery quiesced and
follow the recorded verified-backup restore outcome. Never point older code at
a database that may have advanced.

## Restart and verify

Install the reviewed worker, scheduler, operations, and update unit examples
from the new immutable release as the fixed root-owned files named in Step 13
of `CLIENT-INSTALL-1.0.0.md`, then run `systemctl daemon-reload`. This is
required even when updating an older release that did not yet have every unit.
Restart PHP-FPM, enable/restart the worker and both timers, and confirm no
process retains old code.

In a protected root terminal, recreate the exact `emailer_cli` transient-unit
launcher from Step 8 of the installation manual, then run:

```sh
emailer_cli emailer:release:check
emailer_cli emailer:doctor:run --json
sudo systemctl start emailer-operations.service
```

Confirm version `1.0.0`, schema `20260828000000`, the expected current pointer,
fresh heartbeats, backup/recovery status, suppression integrity, and no critical
Doctor finding. Review the `1.0.0` license/browser/accessibility support record
and the new installation manual with the client operator.

Resume delivery only through the documented `emailer:delivery:resume` command
after the change owner accepts all evidence. Publication, deployment, provider
contact, DNS change, credential rotation, and a live SMTP test remain separate
operator actions.
