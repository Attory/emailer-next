# Ripe 1.3.1 release preparation

This bounded maintenance release coordinates the Ubuntu 26.04 installer profile
and Light 1.2.4 upgrade contract. It does not change skins, application routes,
business rules, migrations, dependency locks, or delivery approval requirements.
No later product phase is implemented.

## Hosting and version bindings

Ubuntu 26.04 amd64/systemd selects native distribution PHP 8.5; Ubuntu 22.04/24.04
and Debian 13 keep the existing PHP 8.4 profiles. No PHP PPA is added on Ubuntu
26.04. Extension packages match an existing selected PHP patch. Per-command APT
retention preserves other installed runtimes and shared software; all unapproved
installed package upgrades/removals remain forbidden. CLI and FPM alternatives,
existing website pools, and unrelated services are retained.

The actual Ubuntu 26.04.1 fixture uses Apache 2.4.66-2ubuntu2.6, PHP
8.5.4-0ubuntu1.5, MariaDB 11.8.6-5ubuntu0.1, Virtualmin 8.3.0-1-gpl/Webmin 2.670,
systemd 259.5-0ubuntu3.4 and Certbot 4.0.0-4. It has two real Virtualmin-created
wildcard Apache websites, individual PHP pools, a shared database sentinel and an
unrelated background service. Ripe retains its existing address-specific Apache
profile; Light remains wildcard-only. The Ubuntu 26 Virtualmin acceptance uses
wildcard sites and does not expand untested address-specific combinations.

Light sources 1.0.0, 1.1.0 and 1.2.0 through 1.2.4 are authenticated under Light's
original release identity. Native Ripe sources 1.0.6, 1.1.0, 1.2.1, 1.2.2 and
1.3.0 can update to 1.3.1. Historical update pairs remain. Original Light 1.0.0
hash identity and both edition signing pins are unchanged. Runtime-pointer
reentry and native custody/recovery guards include only the explicit supported
new versions. There is no Ripe-to-Light downgrade.

## Verification before merge

- All 119 installer regressions pass as root on actual Ubuntu 26/Python 3.14.4,
  including package service fences, owner/permission recovery, runtime selection,
  exact pointer reentry and core-before-host native recovery.
- Full application suite: 426 tests, 5,647 assertions, with the two existing
  nonroot skips covered by the required separate root CI gates. Migrations,
  mappings, static analysis, module boundaries, style and shell checks pass.
- A signed runtime candidate passed all 39 isolated lifecycle cases: fresh Ripe;
  five native predecessors with success, failure/recovery, journal interruption
  and preparing interruption; converted 1.1 update/recovery; and rich/minimal
  original Light 1.0.0 plus all six later supported Light sources.
- Actual native Ubuntu 26/Virtualmin tests passed fresh Ripe, clear DNS failure
  and same-package resume, and Light 1.2.4 to Ripe 1.3.1 upgrade/acceptance.
  New managed-runtime reentry is covered by targeted constructor tests and must
  also pass the exact final signed setup's post-acceptance Check.
- Independent review found and closed unrelated-PHP retention, source 1.3.0
  recovery custody, explicit pointer namespaces, and a pre-existing chained
  native recovery-journal location defect. Five focused journal lifecycle
  tests per edition pass independently. No readiness, signature, fence,
  restoration or no-downgrade control was weakened.

Light 1.2.4 is now published from b844cb64694de69158190287260a7ffe6c092aed.
Its official setup SHA256 is
264d66349a1848600a0685a602b106aab1fba20eae87b356fa95e070b331391b.
The public download is byte-identical to the independently accepted package.
A second complete Ripe candidate uses those actual official Light predecessor
bytes. Its Ubuntu 26 / Virtualmin Upgrade, explicit Accept and repeated Check
pass: all 5,564 installed runtime files verify, the administrator password hash,
identity, encryption material and delivery history are retained, Owner/High
mapping is correct, no contacts/list memberships are manufactured, and delivery
remains held with zero attempts or queued messages. Both existing websites
remain healthy across 916 probes; all five unrelated service PIDs are unchanged.
The two upgrade/acceptance terminal screenshots were refreshed from these actual
1.2.4-to-1.3.1 actions after review found obsolete 1.3.0 paths in inherited images.

The complete required GitHub CI matrix, exact-head merge approval, merge-commit
rebuild and exact final-package acceptance remain release gates. Candidate tests are not represented as final published-package
acceptance. The existing eight CI jobs retain all original members and add
PHP 8.5/MariaDB 11.8 to both application and installer matrices.

## Remaining limitation and release custody

Public ACME certificate issuance is not verified without a dedicated
non-production hostname and DNS authority. Local ACME performs actual HTTP
challenge validation and strict certificate/name checking; no production DNS was
changed. This limitation remains explicit in the guides.

The private complete documentation ZIP combines the authenticated official Light
1.2.4 docs with the Ripe 1.3.1 set. No Ripe source or private material is placed
in the public Light repository. Production servers, credentials and recipients
were not accessed. No deployment or unintended email delivery is authorized or
performed. Published tags/assets and signing identities are preserved.
