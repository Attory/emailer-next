# Emailer Ripe 1.1.0: guided native installation and Light upgrade

Verified starting main: `e522f8ae007c4232b170f4717247378c4b897863` (published
Ripe 1.0.6). Work uses isolated branches/worktrees. This is the explicitly
authorized automated-installation job; it adds no later product phase.

## Delivered scope

The official signed self-contained setup file provisions native Debian hosting,
a dedicated nonroot application identity, isolated PHP pool, dedicated MariaDB
database/account, protected environment/key material, Nginx and HTTPS, first
Owner, worker, scheduler and maintenance timers, command launcher, encrypted
local backup and verified recovery material. Required inputs are a dedicated
hostname when ambiguous, Owner email and hidden password/confirmation. SMTP is
optional and defaults to deferred; the application remains usable for drafting.
Ripe retains High security and requires MFA readiness before delivery.

The authenticated host workflow distinguishes fresh/resume, same-edition update
from original Ripe 1.0.6, original Light 1.0.0 conversion and new Light 1.1.0
conversion, including original1.0.0→newLight→newRipe. Original Ripe 1.0.6's
published Light1.0.0 path remains available but does not recognize new Light.
The new installer supports both explicitly. No general Ripe→Light downgrade
exists. No new schema migration or Composer dependency family is introduced.

## Hosting and isolation

Native support is deliberately Debian13 amd64/systemd, Nginx1.26.3,
Debian PHP8.4.26 and local MariaDB11.8.6, with existing10.11/11.8 accepted.
Application CI covers PHP8.4.24/8.5.9 and MariaDB10.11.19/11.8.9. A real existing
PHP8.2.34 Nginx website and database remained unchanged through continuous
observations during installation and repeat checking, including global CLI/FPM
selection and unrelated service PIDs. Shared servers are reused, never replaced.

Ubuntu, ARM, Apache/Caddy coexistence, custom public ports/subfolders, remote
MariaDB and ordinary file-upload hosting are not demonstrated native profiles.
File upload cannot provide the required persistent worker, timers, identity,
isolated runtime and protected key storage. DNS/provider-firewall changes require
the owner's external account. Actual HTTP01 was tested with a local trusted ACME
authority; real public DNS/public-CA issuance is not claimed. No Docker, Composer,
Mailpit, manually assembled wrapper or verifier sidecar is a customer prerequisite.

## Recovery, preservation and delivery

Before mutation the established update/conversion core authenticates packages,
quiesces work, verifies a complete restorable checkpoint, fences source writers
and records durable phases. Target host resources and database are separate.
A successful handoff permanently retains the old-source fence. Recovery restores
the exact source pointer, account fencing, database/private state and required
service settings. It preserves operation history rather than deleting journals.
Accept is an explicit validated-upgrade action before public sign-in. Delivery
stays held independently until the user completes readiness and chooses Resume.

Account IDs/password hashes, campaign-owned recipient IDs/snapshots, assets,
SMTP ciphertext and original crypto/token contexts, installation identity,
delivery/tracking history, suppression/unsubscribe history, links, required audit
and backups remain intact. Light administrator maps to Owner/High without an
invented MFA grant. Conversion creates no contacts, lists or memberships.

SMTP connection checks use verified STARTTLS/directTLS and AUTH without DATA.
Changed host/login requires a new password; a blank password retains an existing
credential only for the exact same endpoint identity. An explicitly requested,
displayed synthetic test destination produces one separately identified message.
No setup, update, conversion, recovery or acceptance approves/sends a campaign.
Resume can continue already approved due work, as the guide explicitly explains.

## Completed candidate acceptance and review

Disposable native VMs and restricted non-relaying test networks demonstrate:

- Fresh Light and Ripe with no SMTP; actual browser login, background services,
  backup verification and recovery exports. Real Ripe MFA enrollment and
  encrypted SMTP configuration/connection-only verification were exercised.
- Original Ripe1.0.6→1.1.0 uninterrupted host update with original password, MFA,
  SMTP and business/key state preserved. Symfony's generic0666 new updater lock
  was fixed by priming only that known lock via0600 exclusive FD creation;
  complete private checkpoint validation remains strict.
- Original Light1.0.0→Ripe, originalLight→newLight→Ripe and newLight→Ripe; caller
  umask0077; genuine SIGKILL after activation, guided recovery with exact pointer
  restoration, forward retry, acceptance and read-only repeated acceptance.
- Rich original/new Light rehearsals preserve crypto/links/history and restore a
  retained old-Light backup in isolation. Native historical1.0.5→1.0.6 complete
  schema recovery/fault and preparing/journal/migrating interruption gates stay
  active alongside current1.0.6→1.1.0 cases.
- Explicit no-unintended-send evidence: old fixture initialization's one mandatory
  identified synthetic message remains the only message through transitions;
  strict TLS/AUTH probes add no DATA, pending future recipients stay undelivered,
  and explicit one-message tests are separately identified.
- Unsupported host, missing permissions, occupied ports/paths/service/database,
  tampered signed packages, DNS/HTTPS failure with retained progress, interrupted
  fresh setup and reruns, completed repeats and existing website/database reuse.

Independent technical review covers writer isolation, locks/inodes, permissions,
recovery, credentials, signing and preservation. Its original100→newLight→Ripe
chain included a real0077SIGKILL/recover/retry and retained all original rows,
assets/master identity with no fabricated reusable contacts or extra DATA.
Independent Light guide-only installation succeeded without developer repairs.
The separate Ripe guide-only clean walkthrough and final merged-artifact checks
are mandatory release gates, reported with exact assets in the release record.

Local full Ripe application regression passes331tests/11237assertions; static,
style, shell, architecture, migrations/mappings, advisory/license, security/full
history, runtime artifact/public-path/deployment-profile and signed candidate
checks pass. CI retains all three supported quality and three installer matrix
jobs. Its new-Light conversion stage intentionally downloads the official
published Light1.1.0; Light must be released first, never replaced by an unsigned
fixture or a skipped check.

## Documents and final release gates

Four private screenshot-backed HTML/PDF guides replace current installation and
upgrade entries and update the user manual. Advanced operator details are separate.
Real MFA screenshots contain only enabled status, never enrollment secrets.
Every PDF page is rendered/inspected, headings stay with content, commands remain
selectable and guide links resolve to official assets. Font notices are embedded.

The builder requires source-bound complete document inventories. Its private
seven-guide ZIP verifies original-Light signed checksums, runtime/manifest source
and documentation identity before combining public Light and private Ripe guides.
Tampered HTML, invalid signed inventories and mixed authentic runtimes are
rejected. Public Light assets contain only public code/documents and original
AGPL/notices; Ripe remains private and proprietary.

Final assets must be built from reviewed merge commits, accepted unchanged and
signed with the original edition identities before immutable tags are published.
Release records identify exact commits, CI/PRs, complete checksum/signature
inventories and final acceptance evidence. No production installation, database,
credential or recipient was accessed or modified. Hatha Yoga Space and Cacao
Mission deployment is explicitly outside this job.
