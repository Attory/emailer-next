# Privacy and retention

An Administrator or Owner may download one active contact list after recent
contact-data reauthentication in High. Normal removes that extra prompt while
retaining authentication, role authorization, CSRF and redacted audit. The
streamed UTF-8 CSV contains only email,
first name and last name. Downloads are private/no-store responses; a mode-0600
temporary staging file is unlinked as soon as it is opened, then its file
descriptor is closed after the response. The redacted audit records the actor and
opaque list ID, not exported addresses or names. A list export does not mark
any contact eligible to receive mail: global suppression and inactive-contact
checks still apply when a campaign is approved and sent. Browser downloads
remain the operator's private-data retention responsibility.

**Status:** Phase 13 Version-1 privacy acceptance retains the Phase 12 bounded
retention and off-by-default scheduling model.

Open and click tracking are separate Administrator opt-ins and remain disabled
by default. Changes affect only campaigns approved afterwards because the
choice and published-template link allowlist are frozen in the campaign
snapshot. Disabled tracking emits neither an open pixel nor tracked click URL.
Analytics are approximate bearer-link observations, not proof a person read a
message; forwarding can attribute later requests to the original recipient.
Storage excludes address, IP, user agent, headers, and raw destination URL.
Aggregates expire after the configured 1–365 days in the existing 200-row
dry-run/apply housekeeping workflow.

RSVP is independently disabled by default and must be enabled both globally
and on a draft campaign. Approval freezes enablement, the fixed field schema,
and either keep-first or replace-latest repeat behavior. Input is limited to
yes/no/maybe, 0–20 guests, and a 500-character note. Stored rows exclude
address, IP, user agent, and raw token. Reports expose aggregates; Administrator
export requires recent reauthentication in High, is audited, uses an opaque recipient
identity, and neutralizes formulas. RSVP has separate 1–365 day retention.

`emailer:retention:run` defaults to dry-run and processes at most 200 eligible
records per category when `--apply` is explicit. Administrators configure
enforced bounds for revoked sessions (7–365 days), completed queue rows (1–90),
dead letters (30–730), completed delivery attempts (30–730), Doctor runs
(30–730), and an operator log-retention target (1–365). Analytics and RSVP keep
their independent 1–365 day policy. Expired import files and encrypted backups
older than the 2–365 day backup policy are included; automatic backup pruning
always preserves the newest verified backup. Application logs remain stderr,
so journald/Supervisor enforces the configured target outside PHP.

Scheduling is off by default. When enabled, `emailer:operations:run` applies
one bounded batch only when due under a cross-process lock. Preview and applied
runs persist counts without private values. Suppression, contacts, campaign
snapshots/ledger, ambiguous recipient outcomes, templates, and append-only audit
events are deliberately excluded from automatic deletion. Legal holds and
deployment-specific periods remain operator responsibilities.

Emailer Next minimizes data to the approved sending, suppression, response,
operational, and audit purposes of one organisation. It is not a legal-policy
substitute; the deploying organisation decides lawful basis, notices, data
subject procedures, and jurisdiction-specific periods.

## Data classes and defaults

| Data | Purpose | Default exposure | Retention rule |
| --- | --- | --- | --- |
| Contact/address/attributes | Audience and personalization | Masked in ordinary views; never public URL/log | Operator policy; deletion/archive must respect campaign/audit obligations |
| Consent/source metadata | Sending justification and provenance | Authorized admin only | Aligned with contact/legal evidence policy |
| Global suppression | Prevent future unwanted mail | Query result, not recipient disclosure | Durable and independent of analytics expiry |
| Template/campaign snapshots | Prove exactly what was approved/sent | Authorized roles | Archive/retention policy; immutable when referenced |
| Delivery attempts | Recovery and operational status | Redacted diagnostics | Bounded period after campaign, except required evidence |
| Open/click events | Optional approximate analytics | Aggregate/redacted | Off by default; short approved period |
| RSVP responses | Optional recipient-provided response | Restricted view/export | Off by default; campaign-specific approved period |
| Sessions/reset/rate-limit state | Account security | Identity subsystem only | Short functional expiry and cleanup |
| Audit events | Accountability | Redacted read-only | Approved longer period; append-only |
| Logs/health results | Operations/security | Redacted operator access | Short rotation/retention; no raw PII |
| Backups | Recovery | Encrypted operator access | Separate schedule; deletion verified |

## Rules

- Tracking and RSVP are independently disabled by default.
- Open events are approximate and never proof that a human read content.
- Public tokens and URLs contain no raw address.
- Ordinary diagnostics and exports mask/remove personal data unless the
  authorized purpose explicitly needs it.
- Full-address display/export is separately authorized and audited.
- CSV exports neutralize spreadsheet formulas.
- Temporary uploads and rejection reports are private, random named, and
  automatically deleted.
- Retention jobs are observable, idempotent, and dry-run capable.
- Expiring analytics never deletes suppression merely to reduce storage.
- Backups and derived exports need their own deletion/verification path; deleting
  a live row is not proof that all copies disappeared.

## Decisions to record

Before enabling production features, the operator records periods for each row
above, the owner of the policy, review date, legal/business rationale,
backup-propagation behavior, handling of legal holds, and approved export/full
address roles. The UI and Doctor surface missing/invalid policy without exposing
private values.

Tests use synthetic reserved-domain identities only and scan fixtures,
snapshots, logs, diagnostics, and artifacts for accidental personal data.

Phase 5 implements contacts, durable add-only suppression, random-named private
CSV uploads and safe row/reason-only rejection reports, masked ordinary views,
and audited Administrator-only full-address reveal/export. Uploaded CSVs are
integrity checked and expire after two hours if unfinished; a committed import
removes its source file. Template previews use reserved synthetic values only.
At the Phase 5 boundary, tracking, RSVP, retention jobs, campaigns, and delivery
remained unimplemented.

Phase 7 public URLs contain only a version, random handle, and authenticator;
they contain no address, contact ID, campaign ID, or message content. A token
row privately retains its frozen address/scope for no longer than its 365-day
lifetime plus a 30-day operational grace, after which the scheduler removes it
in bounded batches. Source/token rate-limit keys are irreversible keyed hashes.
Unsubscribe responses never display an address. Forwarding grants the bearer
only the ability to suppress the original recipient, and repeated use is safe.
Global suppression remains durable after token expiry or deletion.
