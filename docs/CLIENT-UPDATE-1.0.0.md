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
4. Create the dedicated deployment group if absent, without adding the
   `emailer` account to it. Normalize the recorded releases root to
   `root:emailer-release` mode `2775`, keep every existing semantic-version
   directory root-owned and frozen, and create candidate staging as
   `root:emailer-release` mode `0750`. Do not recursively cross the `current`
   symlink or change external private/key/backup ownership. Equivalent commands,
   after replacing the placeholder with the exact recorded path, are:

   ```sh
   sudo groupadd --system emailer-release
   sudo chown root:emailer-release <RELEASES-DIRECTORY>
   sudo chmod 2775 <RELEASES-DIRECTORY>
   sudo install -d -o root -g emailer-release -m 0750 /var/lib/emailer-candidate
   ```

   If the group already exists, verify it instead of recreating it. Stop unless
   each existing release remains non-writable and ordinary `emailer` processes
   cannot create a file in the releases root.
5. Stage the archive and all four sidecars outside the web root. Install all
   five files as `root:emailer-release` mode `0640` under
   `/var/lib/emailer-candidate/`. Verify the checksum; the supervised updater
   repeats the complete signed-package inspection before any mutation:

   ```sh
   sudo sh -c 'cd /var/lib/emailer-candidate && sha256sum -c emailer-next-1.0.0.zip.sha256'
   ```

Stop on any mismatch, unsupported source/schema/platform result, critical
Doctor finding, missing backup/recovery evidence, or active delivery that
cannot quiesce.

## Run the supervised update

Install the signed archive's `deployment/emailer-update@.service.example` as
root-owned `/etc/systemd/system/emailer-update@.service`, review it against the
paths in the client installation record, and reload systemd. The unit keeps
effective user `emailer` for master-key ownership while granting only that
bounded process the `emailer-release` supplementary group needed to publish and
switch immutable releases. The normal web/worker/scheduler processes never
receive that group.

Run the supervised update through the root-controlled unit:

```sh
sudo systemctl start 'emailer-update@''emailer-next-1.0.0.zip.service'
sudo systemctl status 'emailer-update@''emailer-next-1.0.0.zip.service' --no-pager
```

The updater verifies the pinned signature, manifest, checksum, SBOM, source and
schema compatibility, paths, and disk reserve before mutation. It then locks
maintenance, quiesces/reconciles delivery, creates and verifies a safety backup,
installs immutable `1.0.0`, runs every pending migration, switches the pointer
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
