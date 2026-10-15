# Emailer Ripe 1.0.6 implementation record

Starting main/origin/main: `3927f17d73190b9e99861a8e3b34b572226665a1`.
Branch: `codex/ripe-1.0.6-light-upgrade` in `/tmp/emailer-ripe-106`.
The primary worktree stays on main; the existing 1.0.4 worktree is untouched.

Status: implementation and isolated verification in progress. No release is
available until the exact signed merged package passes all acceptance cases.

The exact published Light commit is
`b8705dc7ea8b42cea5af5ff89b4f8ed937da193a`. Runtime and corresponding-source
checksums match the operator-provided pins. The contract, schema manifest and
synthetic fixture are preserved verbatim as compatibility evidence. Additional
DDL/trigger proof is generated from the actual published runtime.

## Model and contract coverage

Native Ripe receives only forward migration `Version20261015000000`;
historical migrations remain unchanged. A dedicated Light conversion uses the
published source schema and actual native target structures. A complete target
Light-origin schema proof gates baseline creation. The original single Light
migration row and product identity are preserved in explicit transition fields.
Doctrine baseline rows have zero execution time and represent verified schema
establishment, not claimed historical execution of each Ripe migration.

`recipient_id` is the stable campaign audience identity; nullable `contact_id`
records list provenance. Existing Ripe IDs retain their contact reference.
Light IDs remain recipient IDs with no contacts. Campaign audience provenance
is explicit and immutable. Composite audience/delivery/token foreign keys and
identity guards reject mismatches. Readers, delivery, token issuance, views,
tracking and RSVP use the stable recipient scope. Light draft editing and
approval retain their owned audience. Normal new campaigns use reusable lists.

All contract source table columns are compared through bounded keyed
fingerprints. Source-only administrator singleton metadata is omitted as the
contract specifies. The Light administrator-ready timestamp and setup-event
administrator ID remain intact; native Owner aliases are added separately.
Owner role, High security and empty MFA/reset/contact/list/import structures are
verified. Active Light sessions retain their payload/history and receive
explicit revocation; no recent High grant is imported.

Existing Light secret envelopes and token histories retain their bytes, keys,
purposes, expiry and revocation data. Explicit per-record cryptographic context
metadata selects Light or historical Ripe contexts without decryption fallback.
Light backup records receive explicit edition provenance. A unique global
suppression identity preserves valid historical Ripe case aliases and all
original suppression IDs without merging away their records.

## Safety and recovery

The transition runs under maintenance ownership, a persistent file delivery
hold, database delivery quiescence and persistent fencing of the dedicated
application database identity. Shared identities and active claims are refused.
No accepted delivery or claim is reset. Complete encrypted recovery includes
DDL, triggers, ledger, rows, assets, private state, original runtime environment,
master key and old recovery package. An isolated full restore and schema/data
comparison precede mutation. File and directory publication uses durability
barriers. Recovery retains original ownership and leaves delivery held.

The target runtime environment changes only the explicitly selected Ripe trust
and recovery-package paths. Their original file is recoverable. Staged boot,
mapping, migration and read-only Doctor verification precede atomic activation.
Acceptance is durable before source account access is restored. Explicit normal
readiness and delivery resume remain required. Accepted transitions cannot be
recovered as an edition downgrade.

## Validation and remaining release gates

Local PHP 8.4.24 / MariaDB 10.11.19 full suite passed as the application
identity: 301 tests, 9,942 assertions, no skips. An initial root-run suite failed
the existing non-root production-owner assertion and skipped the intended
permission-failure test; rerunning under the correct identity resolved both.
Composer validation, locked advisory audit and the 85-package production license
policy passed. Formatting, PHPStan, architectural boundaries, shell/helper syntax, Compose
validation, secret/private-data history scans, artifact/public/deployment probes
and deterministic signed test-package tooling passed. The unchanged canonical
migration gate failed with Doctrine MigrationConfigurationConflict on a genuinely
fresh disposable database; it has not been bypassed.

Disposable test-key packages have passed these actual runtime/installer cases:

- fresh Ripe 1.0.6 and actual retained-runtime native 1.0.5 update;
- complete native update recovery after a real post-activation missing-key fault;
- published Light full and minimal installation, dry-run and one-invocation
  conversion with complete independent source-column/ID/private-file comparison;
- wrong identity, schema drift, mismatched roots, tampered package, competing
  advisory operation, repeated conversion and accepted downgrade rejection;
- pre-mutation failure, partial committed DDL interruption, interrupted partial
  restoration, automatic post-activation recovery and same-package rerun;
- real old Light cookie invalidation, unchanged password/hash, Owner/High with
  unconfigured MFA, and subsequent ordinary MFA enrollment;
- original active and expired/revoked real-codec URLs, case-insensitive
  suppression, profile fields, assets and actual tracking aggregate updates;
- actual source-serialized accepted/retry envelopes decoded by the target codec;
- retained original Light backup authenticated/restored by unchanged Light code
  into an isolated matching-lock/key clone, followed by packaged conversion;
- conversion-created native Ripe backup restored with the actual Ripe command
  and authenticated every-row/asset drill, preserving owned recipient provenance.

Every conversion/recovery observation recorded zero new attempts and zero sink
messages. A separate explicit post-acceptance delivery resume allowed one
synthetic AUTH-sink verification of the original decrypted SMTP credentials;
that operation left historical recipient attempt fingerprints unchanged.
Prototype packages use disposable keys and are not final release attestations.
The exact candidate and merge artifacts still require matrix CI, independent
exact-head review and final trusted-key package/recovery rehearsals. No tag or
published 1.0.6 artifact exists at this stage.

An automatic approval review rejected removal of the migration check's
`--all-or-nothing` flag. The gate remains unchanged. Operator clarification is
pending concerning nontransactional MariaDB DDL and verified recovery gates.

No production connection or operation has occurred. Light's checkout and
published artifacts remain unchanged. No later release phase is implemented.
