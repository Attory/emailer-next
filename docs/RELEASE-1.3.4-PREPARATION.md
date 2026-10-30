# Ripe 1.3.4 client-upgrade maintenance preparation

Authorized post-Version-1 maintenance, based on clean main
2b1526ec51e42cee0c87d0747ae9d204dafac939. No later product phase is added.
Production deployment remains exclusively in Emailer Administration.

This release repairs the documented stopped-worker/native-health conflict using
an authenticated operation-bound offline check followed by live held-service
verification. The one-time 1.0.5 source is bound to its original signed archive,
commit, schema and legacy lock format. It preserves Normal policy, existing MFA,
credentials, identity and data. Private cache/log/lock preflight keeps original
owners, bytes and inodes; asset permission policy and strict recovery checks are
unchanged. Even an originally accepting source remains held after this route.
Historical releases and signatures are never replaced.

MFA management now returns through a server-owned purpose allowlist. Native DB
session browser testing reproduced an old-cookie background status response
replacing the rotated cookie and causing sign-out. Safe background poll responses
cannot issue a session cookie; ordinary authentication/MFA rotation and revoked
session rejection remain. Fresh-purpose expiry, CSRF, role and method protections
are retained. The UI shows fresh confirmation and its next action. Converted
Owner and existing-MFA package acceptance remain release gates.

Shared compact Template version actions are authored in public Light and ported
to Ripe, with scoped Website/Studio/Warm rules, keyboard focus and mobile spacing.
Preview remains GET; fake-only test and Publish remain separate POST/CSRF forms.
Light 1.2.8 uses the unchanged draft schema profile and its original signer.

No dependency or database migration is added. Current target/release/installer
plumbing advances to1.3.4 and preserves the prior compatibility roster.

Observed local verification is recorded durably in STATUS.md: focused MFA native
browser flow/enrollment/login; desktop/mobile action controls in both editions;
static/architecture; all168 root installer cases; original1.0.5 production-shaped
upgrade, missing-key automatic recovery and actual interrupted-DDL recovery;
complete source preservation and zero sending. Early fixture infrastructure
failures and corrected observers are recorded, not reported as clean passes.

Final full gates, reviewed-head GitHub checks, immutable signed candidates,
exact-package acceptance and human exact-head merge approval remain pending.
Do not publish or deploy from this preparation record.
