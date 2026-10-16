# ADR 0015: Guided native installation and isolated host handoff

- Status: Implemented; final immutable-release acceptance remains a release gate
- Scope: Explicitly authorized 1.1.0 automated Light/Ripe installation job
- Predecessors: ADR 0013 and ADR 0014

## Decision

Support one demonstrated native provisioning platform: Debian 13 amd64 with
systemd, Nginx, the Debian PHP 8.4 packages and MariaDB. Keep application CI on
PHP 8.4/8.5 and MariaDB 10.11/11.8. Do not claim Ubuntu, ARM, Apache or ordinary
file-upload shared-hosting provisioning. Shared hosting cannot run the required
isolated runtime, persistent worker, scheduler, protected writable storage and
privileged service provisioning through file upload alone.

Ship a self-contained executable installation bundle. Authenticate its complete
payload before privileged execution using the existing edition signing identity.
The initial download relies on authenticated official GitHub HTTPS; a key carried
beside an arbitrary archive does not establish independent trust. Preserve
signed source packages, SBOMs, notices and verification sidecars as release assets.
Do not require the customer to assemble them or run Composer.

One host authorization covers installation. Create a dedicated unprivileged
application identity, database/account, PHP pool, website, protected environment,
worker, timers, launcher, encrypted local backups and recovery materials. Preserve
existing CLI and generic FPM alternatives before APT can change them. Reuse
healthy services without replacing their databases or restarting unrelated PHP
pools. Validate Nginx before a scoped reload; obtain trusted HTTPS through HTTP-01
and retain progress when DNS or certificate issuance fails.

Create the first administrator through the protected installer, using their email
as the default login identifier. Fresh SMTP is deferred. Sending remains blocked
until sender/transport readiness is satisfied. SMTP validation performs verified
TLS and authentication without DATA; an explicit browser action is required for
the single displayed test destination. Installation never approves campaigns.

Use a durable phase journal, resource ownership records and one installation
lock. Repeated completed setup is a check/action menu, not destructive reset.
Reconcile only specifically generated writable runtime metadata using descriptor
and inode proofs. Keep keys, assets and business state outside this repair scope.
Prime only the updater's known lock file before its complete private checkpoint,
because Symfony's generic flock implementation otherwise creates it mode 0666.

Reuse the established authenticated update/conversion core and complete recovery
checkpoint. Support original Light 1.0.0 and new Light 1.1.0 to Ripe 1.1.0, and
native Ripe 1.0.6 to 1.1.0. Preserve campaign-owned recipients, crypto editions,
keys, installation identity, account hashes, history and links. The guided host
handoff gives the target isolated host resources and leaves delivery held.
Acceptance is an explicit menu action before signing in; normal MFA and SMTP
readiness must pass before the separate Resume action. Recovery restores the
source pointer and fencing, and never sends queued work automatically.

## Consequences and evidence

A new installation needs only hostname when discovery is ambiguous, administrator
email, hidden password and confirmation. Root-readable recovery and diagnostic
paths are printed at completion; secrets are never arguments or receipts.

Disposable Debian VMs demonstrate fresh installation, PHP 8.2 website coexistence,
DNS/HTTPS failure and resume, caller umask 0077, native original-release updates,
both Light sources, genuine interruption/recovery and no unintended delivery.
The local ACME authority proves HTTP-01 integration, not real public DNS or a
public CA issuance. Final packages must be rebuilt from reviewed merge commits,
accepted unchanged, and signed with the existing keys before tags are published.
Customer guides are generated from reviewed sources and real screenshots; their
complete source/output inventory is bound to the exact release commit.
