# Administrator guide

**Status:** Phase 13 Version-1 administration and operations guide under final
acceptance.

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
- Use `{{ contact.display_name }}` or `{{ contact.first_name }}` for names and
  `{{ unsubscribe_url }}` for the unsubscribe link. Legacy `{{name}}`,
  `{{unsubscribe}}` and `{{email}}` placeholders are unsupported.
- Upload images through the template asset workflow and use the returned
  `/template-assets/…` URL. Outgoing MIME converts those image sources to the
  runtime canonical HTTPS origin; stored versions and approval snapshots stay
  unchanged. Remote image sources are disallowed. CSS styles, classes and
  stylesheet blocks are removed by the current allowlist.

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

Owners see full email addresses in Contacts, Lists and Suppressions, and full
nonsecret SMTP fields. Other roles retain masked ordinary views. Exports and
Administrator reveal require recent authentication and are audited. Public URLs never contain a raw address.
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
## Phase 12 operations

Every signed-in role sees the redacted operational dashboard and persistent
critical Doctor banner. Administrator acknowledgement records awareness only;
it never disables a sending gate. Administrators may set bounded retention
periods, preview one 200-record-per-category batch, explicitly apply it, and
enable scheduling. Owners may select the approved filesystem backup adapter,
set frequency/retention/drill targets, enable scheduling, and create an
on-demand local or verified external copy after recent re-authentication.

No form accepts a filesystem path, provider credential, restore artifact,
package, migration, or deployment instruction. Those remain external/CLI
operator boundaries.

## Lists, templates and newsletter drafts

Each active list has **View, Edit, Send to, Duplicate, Archive** actions, plus
**Download CSV** for Administrators and Owners. Confirm `contact_data_access`
with your current credentials, then use Download CSV beside the intended list.
The download contains only that list's members, including inactive and globally
suppressed contacts; it is a data copy and must not be treated as an eligible
send audience. The UTF-8 CSV has exactly `email,first_name,last_name` in that
order, with RFC-style double-quote escaping and CRLF record endings. A member
whose email compares equal after trimming and case folding appears once. The
stored email spelling and Unicode names are retained. Cells beginning with
spreadsheet-formula characters receive an explicit
`'EMAILER_CSV_SAFE_V1:` prefix; Emailer Next strips it during import before
validating email and checking global suppression. Keep the CSV bytes intact
when moving it through another tool. The filename uses a sanitized list name, UTC timestamp and
random identifier. CSVs contain no consent, audit or suppression details;
use the global suppression controls during every import or send.

View
opens all members in pages of 100 with search; Owners can search full email
addresses and edit every member's email, names, locale, time zone, consent
source and configured custom fields. Edit changes the list name. Add contacts
opens contacts outside the list; Remove from list keeps the contact record and
unsubscribe status. Profiles are shared across lists. Inactive contacts remain
inactive after editing. Correcting an unsubscribed address keeps both the old
and new addresses suppressed. Duplicate requires a new unique name and copies
membership, not separate contact identities. Archive keeps historical records.

Each active template has **Preview, Edit, Send, Duplicate, Archive**, plus its
version history. Preview uses synthetic data. Edit saves a new immutable draft
version; approved/sent content is retained. Duplicate requires a new unique
name and creates a separate draft from the latest content. Names are unique
without regard to case; archived names remain reserved. Template edits preserve
the earlier published version for existing campaigns.

To send a newsletter:

1. Choose **Send to** beside a list or **Send** beside a template. The composer
   preselects that item. Enter a campaign name.
2. Choose existing lists or upload a new CSV with a unique list name. For an
   upload, specify headings/no headings and zero-based column mapping.
3. Choose a template or upload HTML with a unique template name, subject and
   optional preheader. Create campaign publishes the chosen template version for reuse and
   creates a draft; it sends nothing.
4. For CSV uploads, review valid, duplicate, unsubscribed, invalid and rejected
   counts, then **Commit reviewed recipients**. Suppressions are checked again.
   If the session expires, finish the private upload through **Imports**.
5. Review eligible recipients and sender/content settings. **Queue test send**
   sends a test to your signed-in administrator address. Check the received
   message before **Submit for review**.
6. Re-authenticate for approval, **Approve snapshot**, then **Queue now** or
   schedule it. The approval snapshot fixes the content/audience; suppression
   is still checked before every attempt.

Mail settings show the complete nonsecret configuration to Owners. Clicking
**Store secure SMTP configuration** asks for current Owner password and MFA
when the five-minute SMTP confirmation has expired. Confirm and store completes
the same form. Cancel clears only confirmation credentials and leaves unsaved
SMTP fields on the page. Without JavaScript, confirmation fields appear in the
form. The dialog is always present, and its expiry hint is checked on Store
click so an expired grant can be renewed without leaving the form. SMTP passwords remain write-only and storage does not contact a provider.
