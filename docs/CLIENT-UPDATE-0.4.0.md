# Update Emailer Next 0.3.x to the 0.4.0 release candidate

This candidate adds the Phase 12 operational dashboard, persistent Doctor findings, configurable bounded retention, and off-by-default external backup automation. Run the update during a maintenance window. Keep the existing installation, database, private storage, master key, and pinned release public key in place.

## Before updating

1. Confirm the installed version is the latest supported `0.3.x`, Doctor has no unexplained critical findings, and no campaign is actively sending.
2. Create and verify an encrypted backup and confirm the separately stored recovery-key package is available.
3. Copy the candidate files to private staging and verify both checksum and signature:

   ```sh
   sha256sum -c emailer-next-0.4.0.zip.sha256
   php /path/to/current/bin/console emailer:release:inspect /private/staging/emailer-next-0.4.0.zip
   ```

## Run the update

```sh
php /path/to/current/bin/console emailer:release:update /private/staging/emailer-next-0.4.0.zip
```

The updater verifies compatibility, quiesces delivery, creates a safety backup, installs an immutable release, migrates to schema `20260828000000`, switches atomically, and runs Doctor. Never point `0.3.x` code at the migrated database.

## Verify

```sh
php /path/to/releases/current/bin/console emailer:release:check
php /path/to/releases/current/bin/console emailer:doctor:run
php /path/to/releases/current/bin/console emailer:operations:run
```

Confirm version `0.4.0`, schema `20260828000000`, the operational dashboard, scheduler evidence, and no unexplained critical findings. External backup and retention automation remain disabled until an authorized administrator configures bounded policy and an Owner provisions and approves the external destination. Resume delivery only through the documented supervised procedure.

If update verification fails, leave delivery quiesced and follow the recorded recovery result. Because the Phase 12 migration is forward-only, restore the verified pre-update backup instead of repointing old code.
