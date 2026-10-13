# Emailer Next 1.0.5 — Installation security levels

## Scope and authority

Anton authorized the combined 1.0.5 development, review, release and
production deployment after accepting 1.0.4 on 2026-10-13. The starting
`main`, local HEAD and `origin/main` were clean and synchronized at
`5d8b41dd2cb386da50188a2ebf3470987f36ac70`. The feature branch is
`codex/security-levels-1.0.5`.

## Behavior

One constrained `organisation_settings.security_level` value is authoritative
for the entire installation. The forward-only migration adds the field with
default `high` and a database check accepting only `high` or `normal`. Fresh
installations and upgrades retain High. A missing, unreadable, malformed or
unsupported value is interpreted as High. The policy reads the database for
each decision; there is no process or session cache.

High preserves all existing purpose-bound web reauthentication gates. Normal
removes only that additional check. The unchanged login, configured login MFA,
roles, central route and service authorization, CSRF, session revocation,
secure transport and cookies, rate limits, audit, password/recovery policy,
suppression, consent and secret redaction remain mandatory. CLI master-key
rotation explicitly verifies Owner credentials and remains outside the web
policy in both levels.

The dashboard and Security settings page show the level to Administrators and
Owners. Only an Owner may change it. High to Normal needs CSRF, a deliberate
checkbox and purpose-bound recent authentication under the High rule.
Normal to High needs CSRF but no additional recent authentication. The
application service locks the singleton row and records old/new levels, actor,
timestamp and source hash in an append-only redacted audit event in the same
transaction. Repeated same-level requests do not write or audit a transition.
The downgrade warning explicitly describes the effect of an unlocked Owner
session on account and MFA changes and private exports.

## Complete recent-authentication gate inventory

| Purpose | Administrative actions | High | Normal |
| --- | --- | --- | --- |
| `contact_data_access` | Contact address reveal, full-contact export, selected-list CSV download | Recent purpose-bound confirmation | Extra confirmation omitted |
| `backup_management` | Backup creation and backup policy change | Recent purpose-bound confirmation | Extra confirmation omitted |
| `rsvp_data_access` | Private RSVP CSV export | Recent purpose-bound confirmation | Extra confirmation omitted |
| `campaign_approval` | Campaign approval | Recent confirmation or inline credential confirmation | Extra confirmation omitted |
| `mail_configuration` | SMTP/sender settings change | Recent confirmation or inline credential confirmation | Extra confirmation omitted |
| `delivery_policy` | Installation delivery limits change | Recent confirmation or inline credential confirmation | Extra confirmation omitted |
| `password_change` | Current-account password change | Recent purpose-bound confirmation; current password still required | Extra confirmation omitted; current password still required |
| `session_management` | Current-account session revocation | Recent purpose-bound confirmation | Extra confirmation omitted |
| `mfa_management` | MFA start, confirm, disable, recovery-code regeneration | Recent purpose-bound confirmation | Extra confirmation omitted; MFA and recovery controls remain |
| `user_management` | User create, update, delete | Recent confirmation or inline credential confirmation | Extra confirmation omitted; role/last-Owner invariants remain |
| `security_level_change` | High to Normal only | Recent confirmation always mandatory | Not applicable to Normal to High |
| `master_key_rotation` | Protected CLI rotation | Explicit Owner credential verification in both levels | Explicit Owner credential verification in both levels |

The route inventory records the new `GET|HEAD /admin/settings/security` and
`POST /admin/settings/security/update` paths. No other route or dependency was
added. One additive migration advances the schema from `20261008000000` to
`20261013000000`; historical migrations and `composer.lock` remain unchanged.

## Release and recovery contract

The 1.0.5 target accepts exactly retained signed 1.0.4 predecessor evidence.
The additive migration makes `rollback_schema_compatible=false`. A failed
post-migration update must not point 1.0.4 at the advanced schema. The
protected pre-update schema and ordered migration ledger must be recovered and
verified along with the encrypted backup before predecessor recovery. The
signed package, clean-install/update and forced-failure rehearsals are
required before deployment. Production must remain High.

## Validation record

Focused security-level tests, complete PHPUnit, Composer validation/audit,
PHP-CS-Fixer, PHPStan, Deptrac, module-boundary checks, migration/mapping
checks, isolated Mailpit/network tests, security scans, artifact/public-path
checks, privilege rehearsal, CI matrix, exact-head Claude review and signed
release rehearsal are release gates. Record final results, PR, merge, archive
checksum and production backup/deployment evidence in the private operator
record after those gates pass.

The first read-only Claude review of `7887f730b042005a8a3de231a7dcd6c8e65f95c5`
found no blocker. Its warning-copy finding was confirmed and repaired. Its
request-level test gap was confirmed: coverage now includes purpose separation,
stale downgrade forms, and inline user, delivery and mail mutations with and
without CSRF in Normal. The absent audit event for a denied downgrade is not
a release requirement; the authenticated successful transition is redacted
and atomic, and failed credential confirmation is already audited by the
existing reauthentication service. The per-render level reads are deliberate
to avoid stale authorization state. A final exact-head read-only review is
required after these repairs.
