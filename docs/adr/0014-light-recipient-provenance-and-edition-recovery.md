# ADR 0014: Campaign-owned recipients and recoverable edition conversion

- Status: Implemented in the 1.0.6 engineering candidate; release acceptance pending
- Scope: Authorized Emailer Light 1.0.0 to Emailer Ripe 1.0.6 transition
- Predecessor: ADR 0013

## Decision

Keep a stable campaign-scoped `recipient_id` for both recipient models. Native
list audiences retain their original contact identity and foreign key. Light
audiences retain their original recipient identity with a NULL contact foreign
key. Immutable campaign provenance, check constraints, triggers and composite
foreign keys reject mixed or mismatched identities. Conversion creates no
contacts, lists, imports or memberships.

Use explicit stored crypto editions for encrypted records, token codecs and
backup provenance. Existing native contexts remain unchanged. Existing Light
records use exactly their published contexts and retained keys. A retained
Light backup is recovered with unchanged Light code and schema in an isolated
installation, then may undergo the supported forward transition.

The existing setup installer owns the edition conversion. It authenticates the
published source archive and immutable installed tree, validates the complete
source schema, ledger, UUID and protected installation lock, and proves the
complete recovery checkpoint by restoring it into a separate database before
mutation. It verifies all contract table mappings and original column values.
Only after target DDL and mapping proofs does it establish the native migration
baseline, preserving source migration evidence separately. Historical Ripe
migrations remain unchanged.

MariaDB DDL is not transactionally reversible. A forward migration is explicitly
nontransactional. Conversion records durable phases outside the database,
serializes with maintenance and server advisory locks, disables claims, stops
delivery services, fences the dedicated application database account, and
retains a persistent delivery hold. Recovery restores source DDL, data, private
state, configuration, key material and pointer; it never rewinds the operation
journal. Partial recovery can be repeated. Acceptance prohibits edition
rollback and still requires explicit normal delivery readiness and resume.

Native 1.0.5 updates also retain a complete schema/private-state checkpoint for
automatic failure recovery across the new forward migration. Data-only backup
restoration is insufficient to reverse its DDL.

## Consequences and verification

Operators retain the authenticated source package, original Light backups and
keys, protected complete checkpoint and its recovery key. Privileged updater
configuration is separate from the dedicated application identity. New Ripe MFA
enrollment is required; old Light sessions are explicitly revoked without
changing account credentials or fabricating authentication grants.

Full source and target schema proofs are separate for native and Light-derived
installations. Synthetic signed-package rehearsals cover both, empty Light,
interruption during conversion and recovery, failures before migration and after
activation, original URLs/crypto, and retained-backup recovery. Native history
and list workflows remain regression gates. See the implementation and review
records for actual results and unresolved release gates, including the unchanged
canonical all-or-nothing migration gate.
