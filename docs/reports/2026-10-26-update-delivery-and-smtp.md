# Update delivery state, root navigation and current-form SMTP testing

This maintenance change fixes anonymous/authenticated root navigation, preserves
operational delivery state across native updates and corrects SMTP testing of
unsaved form values. It does not approve campaigns or initiate delivery from
saving or navigation. No new dependencies or database migrations are introduced
by these repairs; the existing incomplete-draft schema remains unchanged.

The updater captures its prior accepting-work state under the same row lock as
worker claims, alongside campaign counters and in-flight identities. Dedicated
FPM requests drain gracefully before application replacement. A successful idle
update does not require optional SMTP. Existing holds remain held; previously
active approved delivery resumes only through normal readiness. Uncertain
outcomes are not automatically retried. Interrupted or blocked operations retain
bounded root-owned recovery evidence and a specific next action.

SMTP current-form tests use the entered fields and password without saving or
sending. Blank-password reuse is limited to the saved host/login. A short-lived
HMAC-bound receipt is usable only by a later matching explicit Save. Its private
per-actor attempt generation prevents a failed retest from being undone by a
stale database-session write. Raw provider dialogue, passwords and credentials
are excluded from failure responses and diagnostic details.

Focused real-browser tests use owned internal networks, a strict local TLS CA,
synthetic AUTH and fake SMTP only. Cases cover current versus saved settings,
credential reuse, real certificate/DNS/refusal/authentication failures, editing
while a test runs, failed-retest invalidation, explicit Save, CSRF, field retention,
mobile layout and no SMTP DATA from connection tests. Complete application,
privileged delivery/schema and root installer suites are required separately.
Exact reviewed-head CI and original-key final-package acceptance remain release
gates; passing source tests alone is not a published or deployed fix.

Ripe additionally provides authenticated, no-store lightweight update status for
Owner, Administrator and Operator dashboard/campaign screens. Already-open pages
update their notice, disabled sending controls and actual campaign pause badges
without reloading unsaved fields. Server sending gates remain authoritative.
Auditor receives no sending capability. Light 1.2.7 uses the authenticated existing
1.2.6 schema proof and both migration ledgers when upgrading to Ripe 1.3.3; exact
signed source-package rehearsals are required before release.


Exact-package native acceptance also exposed an aged configured backup-copy
receipt blocking verification after a fresh local safety backup was created.
The repair now verifies that same encrypted backup at the existing approved
destination before checkpoint/publication when the existing policy enables it.
No destination, policy or Doctor severity is changed, and no retention or mail
operation is run. A saved transfer receipt is rechecked against actual bytes,
private mode and ownership. Root copies retain service ownership and sync file
metadata and the destination directory before success. Changed existing objects
are retained and their stale receipt is marked failed. Only bounded structural
blocker codes cross child-console and installer error boundaries.

The edition transition also copies its existing post-conversion verified backup
before runtime checks, after assigning both backup and copy to the trusted
service identity. Copy failure retains the full Light recovery set and restores
the source; bounded categories survive the failure wrapper and the host guide
requires Recover rather than Finish or Accept. Root source/copy custody and the
edition failure action have dedicated regression coverage.
