# Ripe 1.3.11: analytics policy spacing

Includes the approved two-line section-stack wrapper from merge
b8a0701aa58ccbb2c0cd6a9347a95ea9b0fab411. It uses the existing 1.5rem
sibling margin between Save policy's form and the campaign table. Heading,
policy POST/CSRF controls, tracking and statistics remain unchanged; no CSS
rule or dependency is added.

This is runtime-only maintenance for signed Ripe 1.3.10. The specific
1.3.10 → 1.3.11 complete-recovery route and existing target recognition are
added without relaxing same-version, downgrade, legacy or unknown-route checks.
No migration or delivery policy change. Use build-1.3.11-runtime.sh with the
original signing identity; two package passes and the existing verifier/allowlist
remain mandatory. No installer or guide bundle is generated.

Administration follows RELEASE-1.3.10-PREPARATION.md's advanced supervised
procedure, substituting signed source 1.3.10 and target 1.3.11. Reuse each
client's cached runtime archive and existing delta transport; authenticate exact
source/target and the reconstructed SHA. No full uploads, download-backs,
broad matrices, staging, rehearsals or unrelated fixes. Create fresh verified
recovery, complete actual service handoff and retire only the successful
operation's temporary block automatically. Preserve owner intent/settings,
security/MFA/SMTP/content/keys and failed history. No mail, approvals or retries.

Verify visible form-to-table spacing using installed Twig and unchanged CSS
in a focused browser layout check with neutral policy/campaign data; do not
create an authenticated production session or submit forms. Keep each client
journal/recovery separate and preserve Unbubble's application/mail boundaries.
