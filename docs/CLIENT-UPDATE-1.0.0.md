# Update Emailer Next 0.1.x–0.4.x to 1.0.0

The `1.0.0` package directly supports the latest accepted release in each
`0.1.x`, `0.2.x`, `0.3.x`, and `0.4.x` line. Other source versions are not
supported. The target schema is `20260828000000`; the minimum source schema is
`20260824010000`.

## Before updating

1. Confirm the installed source version is an explicitly supported line, the
   immutable current pointer is valid, Doctor has no unexplained critical
   finding, and no campaign is actively sending.
2. Create and verify an encrypted backup. Confirm the separately protected
   recovery package/passphrase and pinned release public key are available.
3. Record the current version/schema/pointer, active-claim count, approved
   `1.0.0` source commit, archive SHA-256, and rollback owner.
4. Stage the archive and all four sidecars outside the web root. Verify the
   checksum and inspect the signed package with the current release CLI:

   ```sh
   sha256sum -c emailer-next-1.0.0.zip.sha256
   php /srv/emailer/releases/current/bin/console emailer:release:inspect /private/staging/emailer-next-1.0.0.zip
   ```

Stop on any mismatch, unsupported source/schema/platform result, critical
Doctor finding, missing backup/recovery evidence, or active delivery that
cannot quiesce.

## Run the supervised update

With the same protected production runtime configuration used by the existing
installation:

```sh
php /srv/emailer/releases/current/bin/console emailer:release:update /private/staging/emailer-next-1.0.0.zip
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

Restart PHP-FPM and every supervised Emailer worker/scheduler/operations process
so none retains old code. Then run:

```sh
php /srv/emailer/releases/current/bin/console emailer:release:check
php /srv/emailer/releases/current/bin/console emailer:doctor:run --json
php /srv/emailer/releases/current/bin/console emailer:operations:run
```

Confirm version `1.0.0`, schema `20260828000000`, the expected current pointer,
fresh heartbeats, backup/recovery status, suppression integrity, and no critical
Doctor finding. Review the `1.0.0` license/browser/accessibility support record
and the new installation manual with the client operator.

Resume delivery only through the documented `emailer:delivery:resume` command
after the change owner accepts all evidence. Publication, deployment, provider
contact, DNS change, credential rotation, and a live SMTP test remain separate
operator actions.
