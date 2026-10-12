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

The application reads only the selected list's members through one unbuffered
sorted database query, using one InnoDB statement snapshot and bounded PHP
memory. It stages the CSV in a mode-0600 immediately unlinked temporary file before returning an
attachment, so a database failure cannot create a successful partial download.
The file descriptor is closed after the response. The UTF-8
CSV has exactly `email,first_name,last_name`, CRLF record endings, standard
double-quote escaping, and one row per trimmed, case-insensitive email.
Stored address spelling and Unicode names round-trip through import. Cells
beginning with spreadsheet-formula characters receive an explicit
`'EMAILER_CSV_SAFE_V1:` prefix, which the importer removes before address,
deduplication and suppression checks. The attachment filename contains only a
sanitized ASCII list name, UTC timestamp and random identifier. Private,
no-store, `nosniff`, no-proxy-buffering and content-length headers are sent.
Redacted audit events record the accepted request and successful generation
with a row count; they hold actor, opaque list ID, format and scope, not the
addresses, names or CSV body.

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

- Focused synthetic HTTP, re-import and large-list tests passed. The large-list
  test checks that the MySQL result is unbuffered as rows are written, in
  addition to checking peak application memory.
- PHP-CS-Fixer, PHPStan and Deptrac passed with no errors or violations.
- The rebuilt, network-isolated application image passed 285 tests and 9,549
  assertions before the final review repairs. The final-head GitHub matrix
  repeats this isolation check.
- The complete local Composer aggregate passed on the final code: strict
  Composer validation and audit, license policy, style, static analysis,
  shell checks, migration and Doctrine mapping checks, PHPUnit (286 tests,
  4,439 assertions), artifact
  build and inspection, public-path/deployment probes, secret/private-data
  scans, and deterministic signed-candidate tooling. The root-owned release
  and service-key privilege rehearsal passed. No production package was
  signed, installed or deployed.
- A read-only Claude adversarial review of the initial branch found a
  formula-prefix re-import flaw, incomplete formula tests, premature success
  reporting on a failing export, an archive/snapshot race, repeated sort cost,
  possible nginx buffering and weak memory evidence. The confirmed issues
  were repaired. A second review found no release blocker but identified an
  archive race that returned 500 and an undersized memory test. Those findings
  were independently confirmed and repaired. Review of the next exact head
  identified a literal safety-marker round-trip edge case and a named
  temporary file that could survive a killed worker. These were confirmed and
  repaired by escaping literal markers and unlinking staging immediately.
  A test now covers archiving between preparation and writing. The large-list
  test checks the unbuffered driver setting while rows are written; its short
  fixture alone cannot prove that a future array-based implementation would
  stay under the memory limit. Exact final-head review and GitHub CI results
  are recorded in draft PR #20.
- The first PHP 8.5 CI attempt exposed a deprecation in the new MySQL PDO
  attribute constant. The exporter and its test now use the PHP 8.4+
  `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` spelling; the replacement matrix is
  the release gate.

## Manual actions and limits

No deployment or merge is part of this job. A future operator must preserve
the exact signed `1.0.3` predecessor evidence before any `1.0.4` update and
must treat the downloaded CSV as private recipient data. A list CSV includes
inactive or globally suppressed members because membership and deliverability
are separate; sending always applies the global suppression gate. The export
has no suppression reasons or consent evidence and must never be treated as a
ready-to-send audience. Formula-leading cells use a reversible prefix that
the application's importer decodes before validation; external tools must
preserve the UTF-8 CSV bytes to retain those unusual values exactly. If two
distinct contacts differ only by address case, the export keeps the member
with the lexically first contact ID and its names. A slow download can occupy
one PHP worker until the client finishes receiving the staged CSV.
