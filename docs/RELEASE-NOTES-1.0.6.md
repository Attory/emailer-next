# Emailer Ripe 1.0.6

Signed-package acceptance is a prerequisite to release availability.

Adds installer-driven conversion from exact Emailer Light 1.0.0 while retaining
campaign-owned recipients, IDs, password hash, content, history, suppressions,
protected settings, tokens and original backups. New Ripe contacts/lists begin
empty. The administrator becomes Owner with High security and unconfigured MFA.

Native Ripe 1.0.5 receives an additive forward update supporting both recipient
models. Existing list workflows and cryptographic contexts remain compatible.
Case-insensitive global suppression preserves historical case aliases.

Edition conversion is serialized, fenced and protected by verified complete
recovery. Activation leaves delivery held until explicit acceptance and normal
readiness/resume. Interrupted unfinished conversion supports recovery; accepted
Ripe to Light downgrade is prohibited. See CLIENT-LIGHT-UPGRADE-1.0.6.md.

Security maintenance updates Symfony HTTP Foundation and YAML from 7.4.17 to
7.4.21 to resolve the advisories found by the release audit. No dependency is
added or removed. MariaDB migration checks retain per-migration transactions
while no longer demanding one transaction across nontransactional DDL.
