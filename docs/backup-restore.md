# Backup and restore

**Status:** Phase 13 Version-1 encrypted backup/recovery/restore and master-key
rotation contract under acceptance.

## Backup scope

A versioned application backup contains:

- database dump;
- templates and immutable versions;
- approved uploaded assets;
- non-secret configuration metadata;
- migration and application versions;
- integrity manifest.

It excludes cache, sessions, temporary imports, ordinary logs unless explicitly
approved, dead transient files, and the plaintext master key. SMTP/provider
secrets remain encrypted records and are useful only with the separately
protected key.

## Protection

Backups are encrypted, integrity checked, randomly named, stored outside
`public/`, and written with restrictive permissions. Success, failure, and
verification are recorded without exposing a path or private contents through
public/admin diagnostics. Spreadsheet exports remain private and formula
neutralized.

The plaintext master key is never part of the ordinary backup. A separate
CLI-only recovery export encrypts a versioned key package using an
operator-provided strong passphrase that is never stored or logged. Doctor warns
until a verified recovery package is recorded.

## Backup procedure

1. Check free space, key access, schema/application version, and destination.
2. Obtain a consistent database/filesystem view.
3. Build from an explicit content allowlist.
4. Encrypt, write manifest and digest, then verify by reading it back.
5. Record redacted metadata and retention eligibility.
6. When explicitly enabled, transfer through the approved filesystem adapter
   using exclusive creation, mode `0600`, byte-count and SHA-256 read-back
   verification, then record only stable redacted evidence.

Create and verify with `emailer:backup:create`; authenticate an existing private
artifact with `emailer:backup:verify <path>`. Tables and approved assets are
streamed from explicit versioned module contributors. A sodium secretstream
envelope derived from the external master key authenticates every chunk and its
final boundary; the inner manifest binds each entry, application/schema
version, backup ID, and creation time.

Export the separate package interactively with
`emailer:secrets:export-recovery-key`. Its hidden passphrase uses Argon2id (3
operations, 64 MiB) and XChaCha20-Poly1305. Recover only a missing key with
`emailer:secrets:recover-master-key`; an existing key is never replaced.

## Master-key rotation recovery boundary

`emailer:secrets:rotate-master-key` is not a routine update command. It requires
interactive active-Owner password/MFA re-authentication, authenticates the
current recovery package/passphrase against the live key, requires quiesced
delivery, and creates a verified pre-rotation backup before any key change. Under the shared
maintenance lock and database row locks, every encrypted record is authenticated
with the old key and re-encrypted with a fresh key. Before publishing the new
external file, the service durably journals both key sides and an opaque
operation ID in the same mode-`0700` directory. The redacted audit marker uses
that ID inside the database transaction. A reader cannot reconcile while the
maintenance lock proves the rotation process is active. After a process crash,
the next key access restores the old key when no committed marker exists or
keeps the new key when the marker committed, then removes the journal. Ordinary
in-process failures use the same reconciliation path.

The reported pre-rotation backup remains encrypted under the old key. Keep it
with the old recovery package in separate historical custody until rotation is
accepted. Before resuming delivery, move the old recovery package out of the
configured output path, export a new package with a new separately held
passphrase, create/verify a new backup encrypted under the new key, run Doctor,
and perform an isolated restore/secret-dependent smoke check. Never delete the
old recovery material merely to make the exporter accept its exclusive output
path.

## Restore procedure

Restore is CLI-only and requires maintenance mode, Owner/operator approval,
format/version/integrity/key/free-space preflight, and a pre-restore backup. It
restores into a staging namespace where practical, validates constraints and
suppression, then switches transactionally or follows a tested rollback.

Quiesce delivery, stop workers/scheduler, and confirm zero active claims. Run
interactive `emailer:backup:restore <path>`. It authenticates and validates the
entire artifact, creates and verifies a pre-restore backup, applies database and
approved assets, and reapplies that safety backup on failure. Delivery remains
quiesced after every outcome; run Doctor and verify suppression before explicit
resume.

The restore command never obtains a backup path or passphrase from an HTTP
request and never prints decrypted contents or key material.

## Record an isolated restore drill

Use a disposable, network-isolated environment with its own database,
private directory, cache, lock directory, asset roots, and backup directory.
Keep every copied key and private artifact inside restrictive storage and remove
the temporary environment after recording its redacted evidence. Do not point
the drill runtime at the live database or run its workers/scheduler.

1. Provision the same application/schema and a separately protected copy of the
   required master key, or recover a missing drill key from the separately held
   package. Copy the exact encrypted backup into the drill backup directory.
2. Quiesce the drill runtime, confirm zero active claims, and run interactively:

   ```sh
   php bin/console emailer:backup:restore /private/drill/backups/backup.enxbackup --drill-receipt
   ```

   This performs the real restore and safety-backup/rollback procedure. It then
   compares every contributed table row and approved asset with the authenticated
   snapshot in bounded batches. Only a successful comparison and successful
   post-restore quiescence can publish a mode-`0600` receipt in the drill backup
   directory. The protected CLI reports its path; no path or private content is
   included in the receipt itself.
3. Transfer the receipt into the originating installation's private backup
   directory using mode `0600` and ownership `emailer:emailer`. Retain the exact
   verified encrypted backup there. Run the commands as the `emailer` service
   user with the protected runtime environment, as described in
   [CLIENT-INSTALL-0.9.1.md](CLIENT-INSTALL-0.9.1.md#8-create-the-first-local-encrypted-backup).
   On the originating installation, run interactively:

   ```sh
   php bin/console emailer:backup:record-restore-drill /private/live/backups/restore-drill-receipt.json
   php bin/console emailer:doctor:run --json
   ```

The recorder authenticates a purpose-separated HMAC derived from the same
external master key, verifies the retained backup, and matches its ID, digest,
size, source/schema, and table/asset inventory. It updates only backup evidence
and appends redacted audit evidence; it does not restore business data or change
delivery state. Future/pre-backup timestamps, tampered receipts, a different
key/backup, unsafe files, and missing/deleted/damaged artifacts fail closed.
Replaying older evidence never advances the restore time to the import time;
cadence uses the time the actual drill ran. Importing a receipt does not refresh
`verified_at` or clear the independent backup-recency gate. A failed restore
followed by safety rollback records no successful restore timestamp. Existing
successful normal restores remain part of the historical restore-cadence metric;
that metric alone does not certify an isolated comparison or human acceptance.

The receipt proves authenticated restore/comparison, not independent key
custody, recovery-passphrase correctness, host/network isolation, or human
approval. Operators remain responsible for those boundaries and their evidence.
Older unsigned diagnostic receipts cannot be imported: repeat the real drill
with this command. No web endpoint accepts a receipt, backup, path, or restore
operation, and no database migration or Doctor-gate exception is introduced.

## Drill and policy

Phase 8 tests corruption, wrong passphrase/key, partial data, incompatible
version, insufficient space, interrupted restore, rollback, and preservation of
suppression. It must also create and verify the encrypted pre-update backup used
by the updater. Phase 12 adds `emailer:operations:run`, a 1–720 hour automated
backup interval, 2–365 day bounded retention, and 7–365 day restore-drill
target. Automation and the adapter default off. The only approved adapter is an
operator-provisioned absolute filesystem mount outside release and private
state roots, supplied through `EMAILER_BACKUP_DESTINATION_DIR`; no path is
stored in the database or projected in UI/logs. At least the newest verified
backup is retained by automatic pruning. Operators still set RPO/RTO and legal
policy outside Git and must perform supervised isolated restore drills.
