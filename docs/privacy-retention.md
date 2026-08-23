# Privacy and retention

**Status:** Phase 7 enforces public unsubscribe minimization and bounded token retention; analytics and full retention automation remain later work.

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
