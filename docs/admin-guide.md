# Administrator guide

**Status:** Product behavior baseline; no administrative UI exists in Phase 0.

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

## Planned workflows

1. Review Configuration Doctor findings after login; red findings persist and
   may block new production sends.
2. Manage contacts/lists through bounded private imports and global
   suppression.
3. Create and publish immutable template versions using allowlisted merge
   fields and synthetic preview data.
4. Draft a campaign, explicitly choose template/sender/audience, review
   exclusions and tracking, run a marked test send, and obtain approval.
5. Type confirmation to queue/schedule. Progress comes from the durable ledger.
6. Pause, resume, or cancel idempotently; in-flight/accepted messages cannot be
   retracted.
7. Review masked reports and append-only audit evidence.

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

Phase 4 begins the admin shell; later phases add the workflows above. The guide
must be updated with tested screenshots containing synthetic data only.
