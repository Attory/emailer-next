# Administrator guide

**Status:** Phase 5 contact, import, suppression, and template administration implemented; campaign and delivery workflows remain planned.

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

## Planned campaign and delivery workflows

1. Review Configuration Doctor findings after login; red findings persist and
   may block new production sends.
2. Draft a campaign, explicitly choose template/sender/audience, review
   exclusions and tracking, run a marked test send, and obtain approval.
3. Type confirmation to queue/schedule. Progress comes from the durable ledger.
4. Pause, resume, or cancel idempotently; in-flight/accepted messages cannot be
   retracted.
5. Review masked reports and append-only audit evidence.

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

Later phases add campaigns and delivery. Any screenshots must contain synthetic data only.
