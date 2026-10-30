# Ripe 1.3.5 maintenance preparation

Unreleased implementation; final source, tests, review, CI, signed artifacts and
client deployment evidence remain required. This document is not release approval.

This iteration adds local in-memory authenticator QR rendering next to the manual
key, for both initial setup and MFA management. Existing TOTP records and sessions
are unchanged by installation. The enrollment POST, CSRF, Owner authorization,
purpose-bound fresh authentication and confirmation remain enforced. Responses
are private/no-store and no-referrer. No QR endpoint, external service, image
file, screenshot, analytics event or secret-bearing diagnostic is created.
BaconQrCode/GD are runtime dependencies; Zxing is a test-only independent decoder.
A real standard-authenticator scan remains a separately recorded acceptance gate.

Installer delivery controls distinguish an independent owner intent/generation
from per-operation root-owned holds. The control directory is beside private
storage, outside DB/private restoration. Release owns privileged completion;
Shared supplies the filesystem protocol and leases. Source/target custody
transfer has its own root receipt and preserves the latest intent. Worker and
FPM writable paths include only the owner subdirectory; the updater may write
operation records. Retain this external control directory in disaster recovery;
never restore an older owner pause generation over live intent.

Whole-host completion, native Finish and edition Accept retire only their exact
attributable operation. Normal readiness continues to name SMTP and required Owner
MFA independently of structural runtime verification. Required Owner MFA now also
participates in ordinary sending gates, matching the existing Resume requirement.
Normal policy retains its documented fresh-authentication behavior.

The first update from an older protocol retains its exact Symfony maintenance
flock through privileged core and host completion. Only root staged-console
children inherit the validated descriptor. Application launchers and unrelated
services do not receive it. The old source cannot acknowledge a pause during this
bootstrap lock; new-aware sources can persist an independent pause and report the
busy maintenance gate truthfully. Source recovery stays held; use the verified
setup to retry the update before Resume. Do not use the historical raw Resume
command to bypass new operation records.

Uncertain outcomes discovered while draining create a separate operation-bound
delivery-review hold. Successful maintenance still retires its installer hold.
The same setup's explicit Resume flow records operator review, retires only that
review hold, and then runs normal Resume/readiness. It never retries failed or
uncertain work. Acknowledging a Doctor finding alone never clears any gate.

New native hop: official 1.3.4 → 1.3.5. Existing native 1.0.6, 1.1.0, 1.2.1,
1.2.2 and 1.3.0 through 1.3.3 retain their supported direct update; the one-time
1.0.5 repair belongs only to
the published signed 1.3.4 installer. Light conversions additionally authenticate
1.2.11 using the established public Light signer and unchanged schema proof.
Historical release artifacts and migration journals are not changed.

Legacy zero delivery flags are unknown intent. Private accepted receipts alone
are not ownership evidence. New conversion completion retires a receipt only
when it matches the protected root accepted operation and exact installation.
Unknown or conflicting legacy evidence stays held for explicit owner review.
No blanket legacy reconciliation or deletion is authorized by version alone.

Production belongs to Emailer Administration. Verify each client independently,
inspect eligible work, preserve owner pauses and Hatha's failed item, create fresh
recovery material, and use only the final authenticated release. No production
source patch or test email is part of this development acceptance.


The original maintenance lease stays held through host completion. After the core
is durably committed and the original application SQL account is uniquely fenced,
the root-owned operation enters `handoff`. A real scheduler may then report only
its own heartbeat while the lease is busy. It cannot recover interrupted work,
purge tokens, materialize campaigns or dispatch queues on that path. Any active
or failed installer core closes it. This supports truthful held-service health
without fabricating heartbeat rows or admitting delivery during migration.

Standalone recovery retry retires predecessor fences only along its verified
protected `previous_operation_id` lineage, with matching installation, source
identity and restored writer fence. Host completion retires only its own verified
children. Independent operations and owner pauses remain. A successful terminal
replay returns without re-projecting delivery over later claims or owner actions.

A standalone retry from an old source with a zero database delivery flag cannot
retain generation-zero inferred enabled intent: the recovered old source may
have acknowledged Pause while its account was open. Authenticated source files
distinguish protocol-aware sources. Invalidate only that unproven generation;
never overwrite a newer independent pause or Resume. Real old-source Pause
between recovery and retry is a release regression gate.

The historical converted1.1.0 rehearsal retains the root-only Ripe recovery
package produced by that original conversion. Before capturing the following
native-update baseline, the source application's own CLI exports a separate
package as the application identity and the bounded fixture launchers select it.
This supplies genuine recovery readiness to the actual held target services;
it does not change historical application bytes, relax key permissions, suppress
Doctor findings, or discard the original recovery material.


Standalone native updates that reopen an older source writer before Finish cannot prove whether that source acknowledged a database-only Pause. Finish releases its completed installer hold but leaves inferred owner intent unknown for explicit review and supported Resume. Newer independently recorded owner actions are preserved. Guided host updates keep the source continuously fenced through completion and can restore proven enabled intent automatically.
