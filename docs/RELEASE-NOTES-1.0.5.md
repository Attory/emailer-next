# Emailer Next 1.0.5 release notes

Emailer Next 1.0.5 adds one installation-wide security level setting. **High**
is the default and recommended level on new and upgraded installations. It
retains every existing recent-reauthentication requirement. An Owner may select
**Normal** to remove extra administrative reauthentication prompts; login,
configured login MFA, roles, CSRF, session controls, auditing, suppression and
consent checks remain active. Only an Owner can change the setting, and
lowering from High requires recent High-mode reauthentication and deliberate
confirmation. Administrators may view the level but cannot change it.

This update adds one forward-only migration to schema `20261013000000`. It
preserves existing data and defaults existing installations to High. There is
no dependency change. The signed update accepts only 1.0.4 as its source and
requires its retained signed package evidence. After migration, do not point
1.0.4 at the advanced schema; use the verified backup and protected schema and
migration-ledger checkpoint for supervised recovery. See
[CLIENT-UPDATE-1.0.5.md](CLIENT-UPDATE-1.0.5.md).
