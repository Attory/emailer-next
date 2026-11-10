# Ripe 1.3.12: approved campaign summary

The existing authenticated campaign detail view shows campaign ID/name/state,
approved subject, sender name/address, reply-to, original recipient count and
approval time. Content, sender and audience values come from the existing frozen
approval snapshots. Current suppression counts are measured against the frozen
audience and labelled separately; later editable lists/contact details cannot
change the original approval summary. The existing effective configured rate is
explicitly current, not a historical rate frozen at approval.

No recipient addresses, raw bodies, credentials or private configuration are
added to the view. Missing approved snapshots fail closed rather than substituting
current settings. Draft review remains current. Roles, MFA, High security, CSRF,
approval requirements, queues, workers and sending controls are unchanged.
No API, schema, dependency, CSS or asset change.

Focused regression uses synthetic list and uploaded audiences, existing approval
with explicit synthetic test waiver, later template/settings/list/contact edits,
current suppression, normal Operator login and the authenticated HTML route.
No test mail or campaign delivery is needed. Verify the rendered Operator view in
a focused browser check. Reuse unchanged access/security/delivery evidence.

This is runtime-only maintenance for signed Ripe 1.3.11. The exact
1.3.11 -> 1.3.12 route retains complete native recovery; same-version, downgrade,
legacy and other unrecognised sources/targets remain rejected. Use
tools/release/build-1.3.12-runtime.sh with the original signing identity,
two deterministic package passes and the existing verifier/artifact allowlist.
No installer or guide bundle is produced.

Follow RELEASE-1.3.10-PREPARATION.md's established advanced supervised update,
substituting exact signed source 1.3.11 and target 1.3.12. Verify host/IP/URL/UUID,
active selector, signed source file inventory and target signature before mutation.
Reuse destination cached archives and delta transfer; no full installer upload or
download-back. Preserve current owner intent, MFA/security/SMTP/settings/content,
failed/uncertain history and all unrelated services. Fresh verified backup/full
recovery, real service handoff and automatic retirement of only this operation's
temporary exclusion remain mandatory. No campaign approval, send or retry.

This job authorises deployment only to Unbubble Emailer. Leave its application,
Caddy, DNS, mail routing/providers and application deployment locks unchanged.
After update, have Bub use the existing dedicated Operator browser/vault path for
bounded read-only verification. If no approved production campaign exists, report
that fact; do not fabricate one or insert fixture data into production.
