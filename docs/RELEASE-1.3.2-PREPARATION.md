# Ripe 1.3.2 preparation: template image editor workflow

This authorized maintenance patch follows 1.3.1 and preserves address-specific
Apache, Ubuntu 26.04/Virtualmin, dashboard skins and security levels. There is
no new database migration and no production access.

The shared editor repair was authored in public Light first and ported to Ripe
with Operator editing/gallery authority and existing appearance modules intact.
No private Ripe source was copied to Light. Create, edit and new-version forms
retain five fields and the HTML cursor through upload, failure and retry. The
persistent gallery supports explicit insertion and copying, alt text and
dimensions. Recovery is per tab/account/editor. Validation, slow-save replies
and non-JavaScript fallback retain unsaved values.

Local evidence before PR:

- Complete application suite: 430 tests, 5924 assertions, exit 0 on PHP 8.4.24
  and MariaDB 10.11.19 with external application egress disabled. Two root-only
  ownership cases pass in the separate root run: 8 tests, 281 assertions.
- Installer: 119 tests pass, including root cases in a cached, network-disabled
  Python 3.12.14 container with read-only source.
- Clean browser harness: 15 cases pass, including the original upload sequence,
  retries, late selection and keyboard focus, cancellation, insertion boundaries,
  recovery/tabs, slow saves, create/edit validation and desktop/mobile bounds.
- Upload/save leaves publication, campaign, queue, delivery-attempt and mail
  counts unchanged. Only a separate explicit campaign test publishes its test
  fixture and sends one queued message through the real worker to Mailpit.
  It uses an empty test list, no contacts and no newsletter recipients. Captured
  MIME contains the canonical HTTPS image; anonymous bytes match its digest.
- Strict PHPStan passes. Revised guides use real browser screenshots.

Independent review's three shared findings were corrected and covered:
quoted-attribute insertion, fallback unsaved metadata and gallery keyboard
focus. An active Auditor denial regression covers the new gallery read route.
The clean Ripe harness reran without its temporary diagnostic resume path.
Active-pointer re-entry, the 1.3.1 recovery/custody guard and current rehearsal
targets were also corrected after release review, with direct regressions.
The current-target roster is 45 cases plus five preserved historical cases.
Final committed-source, usability, CI and exact-package review remain required;
this preparation record does not claim publication.

Packaging adds native 1.3.1 to 1.3.2 and signed Light 1.2.5 to Ripe 1.3.2 while
retaining original Light 1.0.0 and every existing supported predecessor.
Original signing identities, schema 20261015000000, repository visibility and
published tags/assets are unchanged. Public ACME issuance retains its existing
documented limitation. There is no production deployment or real recipient.

Real browser evidence: [retained template fields](guides/images/emailer-ripe-image-fields.png), [saved image gallery](guides/images/emailer-ripe-image-library.png) and [explicit insertion controls](guides/images/emailer-ripe-image-insert.png).
