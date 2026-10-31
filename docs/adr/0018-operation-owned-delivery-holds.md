# ADR0018: Operation-owned holds and independent delivery intent

Status: proposed for the authorized MFA/hold maintenance iteration; implementation and acceptance pending.

Installer maintenance and owner intent are separate authorities. A captured accepting_work boolean is not proof of ownership. Persist owner intent with a monotonic generation and installation UUID outside database/private restore checkpoints. Persist each installer operation's fence in a separate root-controlled namespace, installation/operation-bound and crash-safe. The application may record an owner pause but cannot retire a root fence. Source/target service-identity transfer preserves intent; recovery cannot overwrite a newer owner pause.

The delivery flag becomes a projection of owner intent and outstanding holds. Successful whole-operation completion retires only its own temporary fence after core proof, service handoff, and explicit edition Accept where needed. Core migration success alone is insufficient. Failed/interrupted and source-recovered operations retain their own accurate lifecycle and supported recovery/review action. Updates do not approve or change campaign state, retry failed/ambiguous messages or originate tests.

Completion is independent from readiness: a finished maintenance operation does not remain a maintenance failure because SMTP/MFA is not ready. Normal sending gates must continue to enforce the true readiness requirement. Preserve actual severities and distinguish structural runtime checks from sending prerequisites. Captured enabled intent may project an enabled claim flag after completion while sending remains prevented by current actual readiness; the UI must disclose that distinction and any previously authorized eligible work which can continue once requirements are met.

Owner pause records and installer records use independent locks and atomic fsync publication. Installer completion checks the current owner generation, not merely the captured value. An explicit owner pause arriving during maintenance persists even if a maintenance/database lock is busy; the response must truthfully say it was recorded. Explicit owner Resume clears only owner intent after normal readiness and outstanding hold checks; installer auto-completion must never call global Resume to erase independent pauses.

All records validate owner/mode/link/ancestor, bounded shape, UUID, exact operation, lifecycle and parent/child binding. Terminal retries are idempotent; stale completion cannot retire another hold. Database commit/file publication order and interruptions fail closed. Durable completion and receipt publication are tested at both sides of every release boundary.

Legacy evidence is not upgraded by filename or version. A legacy hold is reconciled only with protected operation and resource-claim proofs binding its exact content/installation/path and completed lifecycle. Even then a separate legacy accepting_work0 pause remains unknown unless independent intent provenance exists. Unknown, conflicting or unsafe evidence remains blocked with a precise review action. Never rewrite historical migration or recovery journals or replace published artifacts.

No new public administrative route or unattended update is introduced. QR rendering is local/in-memory, uses the pending enrollment's existing provisioning URI and keeps no plaintext image artifact. Existing enrolled accounts do not re-enrol. Privacy-sensitive browser capture stays disabled.

This ADR supersedes only the blanket post-success hold/extra-Resume rule in ADR0015 for newly attributable installer operations. Explicit edition Accept, normal readiness, operational recovery and independent owner authorization remain mandatory.


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


Standalone native updates that reopen an older source writer before Finish cannot prove whether that source acknowledged a database-only Pause. Finish releases its completed installer hold but leaves inferred owner intent unknown for explicit review and supported Resume. Newer independently recorded owner actions are preserved. Guided host updates keep the source continuously fenced through completion and can restore proven enabled intent automatically.
