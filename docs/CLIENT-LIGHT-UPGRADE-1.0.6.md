# Emailer Light 1.0.0 to Emailer Ripe 1.0.6

Status: engineering candidate; availability requires the exact operator-signed
release to pass the installation, interruption and recovery rehearsals.

This is an edition transition using the existing protected CLI installer. It
accepts only published Light 1.0.0, contract 1.0, schema 20261014000000 and its
single migration. Ripe-native updates use `emailer:release:update` instead.

## Prerequisites and trust

Use supported PHP 8.4 or 8.5 and MariaDB 10.11 or 11.8. Select the same supported
PHP CLI used by the application services. Retain the exact published Light
runtime archive (SHA-256
`cfd9afb133c3bbfe7da6053aa2d121d325200c46f4db51e784699356d28d0e77`), its
corresponding source (SHA-256
`55932cc1f15f4d78553c00415c968eb1af976c49a088d2aaefcf7925d7dc43da`), existing
Light key packages and backups. Keep the installed Light release immutable.

Verify the Ripe signing-key fingerprint through the established independent
channels. A convenience public key bundled with a package is not a trust anchor.
Compare standalone verifier/inspector hashes with the accepted release record,
then verify the Ripe archive, manifest, checksum, SBOM and detached signature:

```sh
"$PHP_CLI" /var/lib/emailer-candidate/verify-package.php \
  --archive=/var/lib/emailer-candidate/emailer-next-1.0.6.zip \
  --public-key=/etc/emailer-ripe/release-signing.pub \
  --current-version=1.0.6 --expected-version=1.0.6 \
  --expected-source=<EXACT-MERGED-COMMIT>
```

Self-inspection here authenticates the target package. It does not authorize a
same-version native update. The installer independently authenticates the Light
source bytes and verifies its identity, UUIDs, lock, schema, constraints,
triggers, ledger, secret envelopes and audience digests.

Extract the authenticated target into protected immutable staging as described
in the Ripe installation manual. Use a root-controlled transient launcher with
the original protected runtime environment and a separate root-only updater
cache. Its explicitly selected settings must include:

- `EMAILER_RUNTIME_CONFIGURATION_FILE`: the original Light runtime environment;
- `DATABASE_URL`: a separate privileged maintenance identity for the same server
  and database, capable of fencing the dedicated application account and
  restoring schema, rows and triggers;
- `EMAILER_TRANSITION_RECOVERY_DATABASE_URL`: the same server's separately scoped
  `<SOURCE_DATABASE>_edition_recovery` database using maintenance credentials;
- `EMAILER_RELEASE_PUBLIC_KEY_FILE`: the independently pinned Ripe public key;
- `EMAILER_RECOVERY_KEY_FILE`: a separate Ripe recovery-package destination,
  retaining the existing Light package;
- the original private, master-key and releases paths, plus a protected recovery
  destination outside those paths and outside the public tree.

Passwords remain in protected configuration and terminal input, never command
arguments. The normal application database account must be dedicated to this
installation. A shared account is refused. Prepare enough storage for all
existing backup files, assets, database state and a complete encrypted recovery
checkpoint. Quiesce application HTTP access during the maintenance window.

## Inspect and execute

With the protected target launcher and settings above:

```sh
"$PHP_CLI" <VERIFIED-TARGET>/bin/console emailer:setup:install \
  --upgrade-light --edition-action=inspect \
  --target-package=/var/lib/emailer-candidate/emailer-next-1.0.6.zip \
  --source-package=/var/lib/emailer-candidate/emailer-light-1.0.0.zip \
  --recovery-directory=/var/lib/emailer-recovery/light-to-ripe

"$PHP_CLI" <VERIFIED-TARGET>/bin/console emailer:setup:install \
  --upgrade-light --edition-action=execute \
  --target-package=/var/lib/emailer-candidate/emailer-next-1.0.6.zip \
  --source-package=/var/lib/emailer-candidate/emailer-light-1.0.0.zip \
  --recovery-directory=/var/lib/emailer-recovery/light-to-ripe
```

Inspection does not mutate installation data. Execution explains the edition
transition and obtains the Ripe master-key recovery passphrase through protected
interactive input. It acquires maintenance ownership, disables new delivery,
stops the standard Light worker and scheduler/operations services and timers,
fences application database connections, rejects active sends and conflicting
operations, and creates a complete protected checkpoint. It restores that
checkpoint into the isolated rehearsal database and compares every source table
and its schema before conversion.

Each attempt retains a separate recovery directory. The encrypted checkpoint,
its separate mode-0600 recovery key and redacted receipt remain protected by a
mode-0700 directory. Retain this entire set; neither the encrypted file nor its
key alone is sufficient. The recovery set includes original schema, migration
ledger, rows, private files, assets, master key, original runtime configuration
and retained Light recovery package. The unchanged Light code and authenticated
source package remain available for recovery.

The installer stages immutable Ripe code, converts and verifies all mapped
records and the complete target schema, establishes the verified Ripe baseline,
creates a native Ripe backup, validates boot/mappings/Doctor, atomically changes
the current pointer and validates again. The application account remains fenced
until acceptance. Campaign states remain unchanged and delivery remains held.
Acceptance removes the separately scoped rehearsal database's owned tables
after checking its protected installation lease and source inventory. Cleanup
can be repeated after interruption. Successful recovery also clears its owned
rehearsal copy; foreign scratch data is refused and left unchanged. The encrypted
recovery set is retained.
Automatic Ripe backup pruning excludes retained Light backup provenance;
operators must make any later Light-backup disposal decision explicitly.

## Acceptance and delivery hold

Clear/warm the ordinary service cache with the new current release as the
application identity and restart the exact installation's PHP/FPM processes.
Keep workers/timers stopped and HTTP access quiesced until target code replaces
all predecessor processes. Then explicitly accept using the same arguments and
`--edition-action=accept`. Acceptance restores application database access but
leaves delivery blocked. Verify the unchanged administrator password, Owner
role, High security and unconfigured MFA. Use normal Ripe enrollment to enable
MFA; no recovery codes or recent High grant are inherited from a Light session.
Existing Light sessions are retained as revoked records.

Verify old unsubscribe/tracking links, profiles, templates, assets, SMTP-secret
authentication, suppressions, reports and backups. Reusable contacts/lists begin
empty. Existing campaigns retain campaign-owned recipients. Review scheduled
work that became due during maintenance. Start the existing installation's
worker and scheduler/operations services using the new code while delivery is
still held. When their readiness checks pass, the operator may explicitly run:

```sh
"$PHP_CLI" <CURRENT-RELEASE>/bin/console emailer:delivery:resume
```

This root-controlled action removes the accepted edition hold only after the
normal maintenance readiness gate passes. It does not approve or change any
campaign state. Do not release the hold until the operator has reviewed due
work. Subsequent controlled delivery verification is a separate operation.

## Interruption and unfinished-transition recovery

Keep the exact authenticated operation package, source archive, all sidecars,
protected maintenance configuration and recovery sets. After interruption,
rerun the same `execute` invocation. An unfinished conversion is recovered to
verified Light before a new attempt; completed conversion is not repeated.

For an explicit recovery before acceptance, use the same invocation with
`--edition-action=recover`. Recovery keeps delivery off, stops the target
processes, restores source DDL, ledger, rows and required private state, restores
the original pointer, validates the exact source and restores application
account access. It retains a redacted recovery receipt and remains quiesced.
Do not invoke Light delivery resume as an incidental recovery step.

After acceptance, edition recovery to Light is prohibited. Retained old Light
backups stay labeled Light and are never restored directly into Ripe. Restore
an old backup using the unchanged authenticated Light runtime, original key
material and Light schema in an isolated installation, keep delivery quiesced,
then exercise the supported Ripe installer there. Retain original key material
for as long as its Light backups remain in custody, including after later Ripe
master-key rotation.

For isolated old-backup recovery, export the retained original key and lock
from the exact attempt's protected recovery set. This action changes no active
installation or release pointer:

```sh
"$PHP_CLI" <VERIFIED-TARGET>/bin/console emailer:setup:install \
  --upgrade-light --edition-action=export-light-recovery \
  --target-package=/var/lib/emailer-candidate/emailer-next-1.0.6.zip \
  --source-package=/var/lib/emailer-candidate/emailer-light-1.0.0.zip \
  --recovery-directory=/var/lib/emailer-recovery/light-to-ripe/<OPERATION-ID> \
  --light-recovery-export-directory=/var/lib/emailer-isolated-light-recovery
```

Use the exported `keys/master.key` and `private/install.lock` with an isolated
Light release and a new isolated MariaDB database. The exported runtime file is
REFERENCE-ONLY: configure a new isolated DSN and paths and never run against its
original database. Execute the published Light migration with the unchanged
Light runtime, then invoke its `emailer:backup:restore` command against the
retained Light backup under maintenance. The original Light backup codec and
schema handle restoration; no native Ripe restore or downgrade is involved.
After a root-run Light restore, set the isolated private tree's owner/group to
the isolated application account and protect directories with mode `0700` and
files with mode `0600`. Light's asset restoration otherwise inherits the root
process umask. Keep the master key service-owned and mode `0600`.
Keep delivery quiesced and use the signed Ripe installer for any subsequent
conversion of that recovered isolated installation.

An active-send refusal does not stop a source worker before successful quiescence
and does not reset claims. Before schema mutation, recovery can verify unchanged
Light and restore the original application account while retaining its durable
no-new-work state; existing in-flight evidence stays intact. Do not fabricate
idleness or resume delivery to make inspection pass.

### Explicitly abandoning an unfinished conversion

After successful verified recovery only, if the operator deliberately chooses to
keep Light, confirm `current` resolves to the unchanged `1.0.0` release, the
protected receipt says `recovered`, the original Light schema/keys/configuration
are restored, and Light maintenance Doctor has no unexplained critical finding.
Review work that became due. Restart only the original Light processes using its
original protected environment, then explicitly run:

```sh
"$PHP_CLI" <UNCHANGED-LIGHT-RELEASE>/bin/console emailer:delivery:resume
```

This is a deliberate return to normal Light operation after an unfinished failed
attempt, not an incidental recovery action. Retain the recovered receipt and all
recovery assets; do not delete the hold marker to evade package binding. This
procedure is prohibited after acceptance or while Ripe is current.
