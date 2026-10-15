# Isolated 1.0.6 package rehearsals

The harness uses disposable MariaDB databases, protected temporary directories,
test signing keys, the actual published Light archive, and an internal Docker
network with Mailpit. The fixture seeder is a test-only helper that runs against
the published Light runtime after its interactive installer completes. It is
excluded from runtime artifacts. No production configuration is accepted.

The package installer performs every target schema change. Fixture SQL populates
only the already-installed source schema with published synthetic identities and
additional synthetic histories; it never constructs a target database.

The fixture's semantic `permanent_failure` outcome maps to Light's actual
`delivery_attempt.state = permanent`; delivery-ledger state remains
`permanent_failure`. Session payloads are preserved and active Light sessions are
explicitly revoked because none can constitute Ripe High reauthentication.

Representative retries and retained accepted/retry Messenger envelopes are created
with the actual published source serializer. Target decoding dispatches nothing.
Conversion/recovery observers compare sink counters and historical attempt
fingerprints. Only a separate explicit accepted-hold release permits one synthetic
AUTH-sink verification, after which native backup restoration leaves delivery
quiesced again. Run fixtures sequentially when they share a sink counter.

CI rebuilds accepted native 1.0.5 source for its disposable test-key package; local
acceptance additionally authenticates the retained originally signed archive and
uses its unchanged runtime. Final availability requires the exact merged package
signed with the independently trusted Ripe key, not a disposable CI key.

The full Light fixture includes overdue scheduled work as well as paused retry and
accepted recipients. Conversion preserves those states under the durable hold.
The separate credential check opens no scheduler or worker, even after its explicit
hold release; historical recipients are not dispatched.
