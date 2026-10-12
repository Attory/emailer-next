# Release 1.0.4 — Selected-list CSV download

Anton authorized two bounded objectives on 2026-10-12: a read-only recovery of
one named Hatha Yoga Space list and the effective global suppression set, plus
an Admin → Lists CSV download for every active list. The recovery data stays
outside this repository. The feature starts from clean, synchronized main at
`371d1ed2796ad0f78094554e568c5c9e2934b790`, after PR #19 merged.

## Scope

- Add a clearly labeled list-row and detail-page Download CSV control for
  Administrators and Owners only.
- Require the central route policy, service-level permission, recent
  `contact_data_access` confirmation, and list-specific CSRF.
- Export only the selected active list as bounded-memory UTF-8
  `email,first_name,last_name` CSV, deduplicated by trimmed case-insensitive
  address, with reversible spreadsheet protection, safe quoting, CRLF endings,
  filename and headers.
- Audit the request without addresses or CSV content.
- Test authorization, exact membership, Unicode/CSV escaping, empty and large
  lists, headers, privacy, and multi-page deduplication.
- Maintain the unchanged schema and dependencies, package a prospective
  `1.0.4` candidate supporting exactly signed `1.0.3`, and run all canonical
  local and CI gates plus a read-only Claude adversarial review.

No contact, membership, consent, suppression, campaign, send, provider, or
production deployment mutation is authorized. Do not merge, deploy, or start
a later phase in this job. Preserve the exact-head approval boundary.
