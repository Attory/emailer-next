# Ripe 1.3.10: campaign analytics wording

Includes the approved wording from merge d1e6c7bb6a0652a537222eecf9f7efafbf1f6bbe:
heading “Campaign analytics”, with the introductory privacy paragraph removed.
Tracking, statistics, controls and permissions are unchanged.

This is a **runtime-only maintenance release for signed Ripe 1.3.9**. It adds the
specific 1.3.9 → 1.3.10 complete-recovery route and recognizes the target in the
existing native identity, recovery and finish branches. Same-version updates,
downgrades, the legacy 1.0.5 exception and unrecognized future targets remain
rejected. No schema, dependency, worker, SMTP or delivery-policy change occurs.

Build from committed clean source using tools/release/build-1.3.10-runtime.sh,
the existing original signing key and independent public pin. The existing generic
artifact builder and signed-package verifier remain authoritative. Two package
passes must produce identical archives and sidecars. No full one-file installer,
guide/PDF rebuild, compatibility matrix or staging rehearsal is needed for this
narrow supervised runtime release; older official assets remain immutable.

Administration uses the established advanced supervised native procedure. Verify
host/IP/URL/UUID, original source and target signatures, complete installed source
inventory, current owner intent, claims and eligible work before mutation. Reuse
the destination's authenticated 1.3.9 archive/cache and rsync or equivalent delta
transport. Verify the reconstructed target SHA-256 and detached signature on each
host. Do not transfer full installers or download published assets back for review.

Create fresh verified encrypted backup and complete recovery. Fence only this
instance's writers/web endpoint, invoke the signed target CLI with the retained
source package, then complete its real service handoff and supported native Finish
internally. Successful whole-operation completion retires only its temporary
sending exclusion; no extra owner Resume is required. Preserve independent pauses,
settings, MFA, security levels, keys, content and SMTP. Never approve drafts,
send tests/campaigns or retry failed/uncertain messages. Hatha's failed rows remain.
Verify actual account access, services, fresh heartbeats, readiness and rendered
wording. Keep the Unbubble application, Caddy, DNS, mail routing/providers and its
deployment locks outside this Emailer operation.

Retain the source archive, signed target/sidecars, backup, full native recovery and
redacted per-client checkpoint. An interruption uses the same target CLI/package
and its recorded Recover/Finish operation; never edit journals or improvise a
pointer downgrade. Fresh installations/edition conversions use their existing
published one-file release first; this package does not add a fresh installer.
