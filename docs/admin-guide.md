# Administrator guide

**Status:** Phase 11 administration, optional tracking, and optional RSVP
candidate workflows documented.

Emailer Next has one organisation per installation and individual
administrator accounts. There is no self-registration, shared global password,
tenant selector, or public campaign API.

## Roles

- **Owner:** full access, secrets/recovery, roles, restore and upgrade approval.
  At least one active Owner always remains.
- **Administrator:** configuration except master-key recovery; user management
  except protected Owner changes; content, contacts, campaigns, and reports.
- **Operator:** contacts, templates, drafts, test sends, scheduling, and
  pause/resume/cancel; no secrets, roles, backups, retention, or security policy.
- **Auditor:** read-only redacted campaign, health, report, and audit access.

The UI may omit unavailable controls for clarity, but server-side authorization
always enforces the same ceiling. Sensitive operations request recent
re-authentication.

## Implemented identity workflows

- Sign in with an individual email/password and, when enabled, a TOTP or unused
  recovery code. Public failures deliberately do not explain which factor failed.
- Review the current account, active sessions, MFA state, and redacted audit
  history from the server-rendered admin navigation.
- Re-authenticate for the displayed purpose before changing users/roles,
  password, MFA, or sessions. The assertion expires after five minutes and is
  not valid for another purpose.
- Owners and Administrators create and manage accounts within their authority.
  Only an Owner can affect an Owner, and the last active Owner cannot be
  demoted, disabled, or deleted.
- Request password recovery without account disclosure. Development and CI
  delivery appears only in Mailpit; links expire after 30 minutes and work once.

## Implemented Phase 5 content workflows

- Operators manage masked contacts, bounded custom fields, lists, memberships,
  private CSV preview/commit, durable global suppression, and immutable template
  versions.
- Administrators may reveal or export full addresses only after recent
  re-authentication; those actions are CSRF protected and audited. Exported CSV
  cells are neutralized against spreadsheet formulas.
- Import reports identify only source row and safe reason. Suppressed addresses
  are excluded again at commit. There is no Phase 5 suppression removal path.
- Template HTML is sanitized with a strict allowlist. Merge fields are fixed,
  non-evaluating tokens; previews use synthetic values, and the test action
  validates through the fake-only path without sending mail.

## Implemented campaign and delivery workflows

1. Draft a campaign, explicitly choose template/sender/audience, review
   exclusions and tracking, run a marked test send, and obtain approval.
2. Type confirmation to queue/schedule. Progress comes from the durable ledger.
3. Pause, resume, or cancel idempotently; in-flight/accepted messages cannot be
   retracted.
4. Review masked progress and append-only audit evidence.

Every recipient message contains a manual unsubscribe link and header. The
public confirmation never displays the address and does not use an admin login
or cookie. Manual and mailbox-provider one-click requests immediately create
durable global suppression and are safe to repeat. A forwarded link can only
suppress the original recipient represented by that bearer token.

RFC 8058 one-click advertising is disabled until an Owner re-authenticates and
records that the selected provider DKIM-signs both unsubscribe headers. This is
an operator attestation, not an application-generated DKIM signature; Phase 8
Doctor readiness checks remain required before production sending is healthy.

“Accepted” means the configured transport accepted the message, not that it
reached an inbox. Open events, if enabled, are approximate.

## Privacy and safety cues

Ordinary views mask recipient addresses. Full-address access and exports are
separately authorized and audited. Public URLs never contain a raw address.
Tracking and RSVP are independently off by default. Global suppression is
checked immediately before every attempt and cannot be temporarily bypassed.

Campaign approval must clearly show audience, suppression count, scheduled time
and zone, tracking/RSVP settings, sender identity, template version, and
test-send status. Confirmation pages explain irreversible or externally visible
effects.

## Phase 8 operations workflows

- Auditors can view/export redacted Doctor evidence. Administrators can run
  checks, but no acknowledgement disables a critical sending gate.
- Owners can view redacted backup metadata and create a verified encrypted
  backup after recent re-authentication. Paths/content stay out of the browser;
  restore and key recovery are CLI-only.
- Administrators can preview or apply one bounded housekeeping batch.
  Suppression is never deleted.
- Owners can view version/schema/pointer status. Package upload, installation,
  migration, update, rollback, and switching remain CLI-only.

Phase 9 acceptance verifies that campaign review displays the currently
selected, suppressed, and eligible audience counts; sender identity; immutable
template name/version; scheduled time and organisation time zone; tracking,
RSVP and one-click state; and test-send status before approval. Any screenshots
must contain synthetic data only.
