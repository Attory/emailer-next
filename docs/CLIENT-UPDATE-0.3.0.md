# Update Emailer Next 0.2.x to the 0.3.0 release candidate

This candidate adds the optional RSVP module. Run the update during a maintenance window. Keep the existing installation, database, private storage, master key, and pinned release public key in place.

## Before updating

1. Confirm the installed version is `0.2.x`, the application Doctor has no critical findings, and no campaign is actively sending.
2. Copy all files from this candidate directory to a private staging directory on the server. Do not place signing material or package metadata under the web document root.
3. Verify the archive using the supplied checksum and the independently pinned public key:

   ```sh
   sha256sum -c emailer-next-0.3.0.zip.sha256
   php /path/to/current/bin/console emailer:release:inspect /private/staging/emailer-next-0.3.0.zip
   ```

## Run the update

From the current installation, with the same production environment variables used by the application:

```sh
php /path/to/current/bin/console emailer:release:update /private/staging/emailer-next-0.3.0.zip
```

The updater verifies the signature and compatibility, quiesces delivery, creates and verifies a backup, installs an immutable release, runs the migration, switches the current pointer, and runs Doctor. Do not manually run the Phase 11 migration or change the current symlink.

## Verify

```sh
php /path/to/releases/current/bin/console emailer:release:check
php /path/to/releases/current/bin/console emailer:doctor:run
```

Confirm version `0.3.0`, schema `20260827000000`, no critical Doctor findings, and then resume delivery using the existing supervised operations procedure. RSVP remains disabled until an administrator explicitly enables it globally and an operator explicitly enables it on a draft campaign using a template containing `{{ rsvp_url }}`.

If the updater reports failure, leave delivery quiesced and follow its recovery result. Because the Phase 11 schema is forward-only, never repoint old code at the migrated database; restore the verified pre-update backup with the documented recovery procedure.
