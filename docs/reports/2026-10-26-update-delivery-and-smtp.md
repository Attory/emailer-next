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
