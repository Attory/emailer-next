# ADR 0016: Ripe Apache address groups

- Status: Implemented; exact signed-package acceptance gates release
- Scope: Authorized edition-specific Apache installer correction, Ripe 1.3.0 / Light 1.2.3

Apache selects an address group before considering ServerName. Ripe therefore
plans literal IPv4/IPv6 groups from apache2ctl -S on explicit ports 80 and 443,
with wildcard fallback for fresh setup. It never changes Listen directives,
existing site files, PHP defaults, Nginx or shared database software. Light
retains wildcard-only provisioning and explains that address-specific hosting
requires Ripe. Both retain 1.2.2's unrelated-Nginx8081 coexistence correction.

Persist the planned groups before provisioning. Recheck the parsed inventory
before publishing a site; newly shadowing groups stop the operation with its
state intact. Updates and conversions preserve the adopted Emailer site's exact
validated binding set, rather than copying other sites' addresses. Preserve
original bytes for recovery. Literal selectors are canonicalized; wildcard ports,
unspecified/scoped addresses and ambiguous source blocks fail closed. Existing
mandatory /proc socket ownership proofs on both public ports remain in force.

Exact-IP host-handoff health probes bind their source to loopback and connect
to the retained destination address with normal certificate/name verification.
The maintenance gate remains local-only. No business routes, schema, migration,
crypto context or delivery approval behavior changes. Original signing pins and
edition source separation are retained. Public ACME issuance remains a separate
unverified limitation without dedicated non-production DNS authority.
