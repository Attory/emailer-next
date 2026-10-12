# Emailer Next 1.0.4 — Selected-list CSV download

## Authority and starting state

Anton authorized the bounded job in `docs/spec/RELEASE-1.0.4-JOB.md` on
2026-10-12. Fetch/prune verified clean local `main`, `origin/main` and `HEAD`
at `371d1ed2796ad0f78094554e568c5c9e2934b790`; no PR was open. The
Hatha Yoga Space installation's immutable current pointer resolved to
`/srv/emailer/releases/1.0.3`. Branch
`codex/list-csv-download-1.0.4` starts from that exact main SHA.

## Changes

Administrators and Owners can use **Download CSV** beside any active list or
on its detail page. The POST requires list-specific CSRF and recent
`contact_data_access` re-authentication. Central route policy and service
authorization both enforce Administrator rank. An unknown or archived list
does not export.

The application reads only the selected list's members in 500-row keyset
pages under a read-only database snapshot and streams the response. The UTF-8
CSV has exactly `email,first_name,last_name`, CRLF record endings, standard
double-quote escaping, and one row per trimmed, case-insensitive email.
Stored address spelling and Unicode names remain, with a leading apostrophe
on spreadsheet-formula-leading cells. The attachment filename contains only a
sanitized ASCII list name, UTC timestamp and random identifier. Private,
no-store and `nosniff` headers are sent. The audit event holds actor, opaque
list ID, format and scope, not the addresses, names or CSV body.

No contact, membership, consent, suppression, campaign, mail or production
data was changed. No migration or dependency was added; schema remains
`20261008000000` with 14 migrations. The prospective `1.0.4` patch accepts
exactly `1.0.3` and declares compatible-schema pointer rollback. Production
update still requires the exact retained operator-signed predecessor package
and sidecars, verified backup, approval and independent operator key.

## Read-only recovery

The exact named Hatha list and global suppression table were read in one
consistent, read-only transaction using only the three requested membership
fields and suppression addresses. The two CSVs were written outside Git in a
mode-0700 user directory with mode-0600 files. No address or CSV body was
printed in chat or included in this report. The private paths and counts are
reported directly to Anton, not recorded in repository history.

## Validation and review

- Focused synthetic HTTP and multi-page integration tests passed: 3 tests,
  52 assertions.
- PHPStan passed with no errors.
- Complete local aggregate, network-isolated tests, candidate/package checks,
  security scans, GitHub CI, and Claude review: pending final branch head.

## Manual actions and limits

No deployment or merge is part of this job. A future operator must preserve
the exact signed `1.0.3` predecessor evidence before any `1.0.4` update and
must treat the downloaded CSV as private recipient data. A list CSV includes
inactive or globally suppressed members because membership and deliverability
are separate; sending always applies the global suppression gate. The export
has no suppression reasons or consent evidence and must never be treated as a
ready-to-send audience. Spreadsheet-neutralized cells starting with formula
characters need review before unusual values are re-imported.
