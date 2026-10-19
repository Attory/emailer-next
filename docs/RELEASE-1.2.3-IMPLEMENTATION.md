# ADR 0016: Ripe Apache address groups

- Status: Implemented; exact signed-package acceptance gates release
- Scope: Authorized edition-specific Apache installer correction, 1.2.3

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

## Reviewed candidate evidence

Light PR9 and Ripe PR28 preserve original signing identities. Root installer
regressions pass (Light95, Ripe104); complete local application suites pass
(Light162tests/1026assertions; Ripe335tests/5055assertions), along with static,
style and architectural checks. Required application CI covers PHP8.4.24/8.5.9
and MariaDB10.11.19/11.8.9. Native hosting uses PHP8.4.26.

Disposable Ubuntu22.04.5/Apache2.4.52 tests pass fresh Ripe setup with literal
IPv4/IPv6 groups, first-Owner interruption/resume, completed repeat, strict HTTPS,
local-source exact-IP health checks and encrypted backup creation/restore.
Non-loopback maintenance requests return503; loopback-source probes return200.
An original published Ripe1.2.2 installation updates to the candidate while
retaining its sole10.0.2.15 binding, complete original site recovery bytes,
identity/password hash/master key/encrypted contexts and delivery hold.

Independent technical review corrected clean-host planning before Apache exists,
source-binding broadening, omitted122version guards and proxy-sensitive local
health probes. Independent guide-only Light123→Ripe123 Upgrade/Accept/Check,
unchanged-password OwnerHigh sign-in, MFA enrollment and freshTOTP sign-in pass.
No hidden setup step was needed. All126pre-existing configuration files, five
service MainPIDs, Nginx workers, globalPHP and shared database remain unchanged;
1398continuous probes report zero failures in that walkthrough interval.
Delivery attempts, queue, contacts and memberships remain empty; no campaign,
test send or Resume action was invoked. All7guides/40PDFpages pass visual review;
current upgrade/accept terminal screenshots use the actual recorded walkthrough.

The release gate additionally requires complete current-head CI after public
Light123publication, exact merged original-key packages, final native/package
acceptance and remote published-asset authentication. No immutable tag is
published before package acceptance. No production application/server/DNS is
accessed or changed. Public ACME staging remains unverified without dedicated
non-production DNS authority.
