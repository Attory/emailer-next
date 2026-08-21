# Phase 5 completion report

**Date:** 2026-08-22

**Phase/job:** Phase 5 — Contacts, lists, suppression, imports, and templates

**Branch:** `codex/phase-5-contacts-imports-templates`

**Base:** `origin/main` at
`fb544ae025fe2800d5d558cfd6aed1ee35fbeda9`

**Result:** The bounded Phase 5 implementation and complete local aggregate
gate pass. Exact draft-pull-request head and terminal GitHub check results are
recorded in the final handoff and on the pull request.

## Starting state and scope

Origin was fetched with pruning. Local `main`, `origin/main`, and `HEAD` were
clean and synchronized at the Phase 4 squash merge before this branch was
created. The master specification, phase jobs, clean-room checklist, Phase 4
completion report, and relevant architecture, ADR, security, threat, privacy,
route, data, testing, quality, development, installation, and operations
documents were read before implementation. No material documentation conflict
was found.

Only Phase 5 recipient/content administration was implemented. There is no
campaign state, audience snapshot, queue message, worker, provider delivery,
public recipient action, tracking, RSVP, backup engine, deployment, DNS, or
production SMTP behavior.

## Implemented bounded scope

- Contacts retain the entered address and use a conservative comparison value
  that preserves local-part case, dots, and plus addressing while normalizing
  the domain. Ordinary views are masked. Profiles, locale, time zone, consent
  metadata, archive state, and Administrator-defined bounded custom fields are
  validated in application services.
- Contacts can belong to multiple archiveable lists through unique,
  concurrency-safe memberships. Global suppression is durable, add-only in
  this phase, and idempotent under a unique comparison-address constraint.
- Full-address reveal and export require Administrator authority, a fresh
  purpose-bound `contact_data_access` reauthentication, POST, CSRF, and an
  append-only audit event. Export cells are spreadsheet-formula neutralized.
  CSV output is staged in an unlinked private temporary file so audit must
  complete before any response bytes are disclosed.
- CSV uploads are limited to 10 MiB, 50,000 rows, 32 columns, and 500 bytes per
  field. The parser supports explicit headered/headerless modes and UTF-8 BOM,
  rejects malformed encoding/control content, validates mapped profiles,
  deduplicates within the upload and selected list, and reports valid,
  duplicate, suppressed, invalid, and rejected counts before commit.
- Import files use 192-bit random names outside `public/`, restrictive modes,
  inode/link/integrity validation, and two-hour expiry. Candidates are staged in
  MariaDB to bound application memory. Preview/report replacement is serialized
  under the import row lock; commit is single-use, locks state, rechecks global
  suppression, uses concurrency-safe inserts, and immediately requests source
  deletion. Reports contain only row number and an allowlisted safe reason.
- Templates have archiveable metadata and immutable versions with subject,
  preheader, sanitized HTML, generated or supplied plain text, exact merge/link
  manifests, and SHA-256 content digests. Publication selects only a version of
  the same template under a row lock.
- Template syntax accepts only fixed non-evaluating merge fields. Header
  controls, Twig/PHP constructs, unsafe attribute contexts, unknown fields,
  credentials, unsafe schemes, relative links, remote images, and unregistered
  local assets are rejected. Preview uses synthetic values only; the Phase 5
  test action performs validation and audit without sending mail.
- JPEG, PNG, and WebP uploads are link/inode/size checked, copied to a private
  quarantine, content-detected, dimension bounded, decoded with GD, and
  re-encoded without supplied metadata to random raster-only public names.

## Dependencies, migration, and routes

- Required platform extensions added: `ext-dom` and `ext-gd`; the PHP base
  supplies DOM and the Docker image builds GD with JPEG, PNG, and WebP support.
- Composer dependency added: `symfony/html-sanitizer` `7.4.*` (locked at
  `v7.4.14`) and its locked URI/HTML parser transitive dependencies.
- Forward-only migration added:
  `DoctrineMigrations\Version20260819000000`.
- Tables added: `contact`, `contact_field_definition`, `contact_attribute`,
  `contact_list`, `list_membership`, `suppression`, `contact_import`,
  `contact_import_candidate`, `email_template`, `template_version`, and
  `template_asset`.
- Administration routes added for contacts, custom fields, lists,
  memberships, imports/preview/commit/private reports, full export,
  suppressions, templates/versions/publication/archive/preview/fake test, and
  raster asset upload. Every route is registered in the fail-closed Phase 4
  method/role policy; every mutation is POST-only and CSRF protected.

## Validation evidence

The rebuilt PHP 8.4.23 application image contains DOM and GD and passes strict
Composer validation plus the locked advisory/abandonment audit. A new isolated
MariaDB database applied all six migrations from empty through
`Version20260819000000` in 46 SQL queries; a second run was up to date and
Doctrine mapping validation passed.

The focused Phase 5 suites pass. The complete Phase 0–5 suite passes 121 tests
with 1,546 assertions. Coverage includes
conservative address normalization/masking, role boundaries, purpose-bound
recent authentication, CSRF/method safety, audit redaction, formula
neutralization, headered/BOM and headerless import, duplicate/suppression
handling, invalid encoding/control/profile data, private storage integrity,
safe reports, bounded-memory 5,000-row staging, template XSS/header/merge/link
rejection, immutable publication, synthetic preview, fake-only testing,
symlink/traversal/MIME/dimension rejection, and raster re-encoding.

The aggregate `composer check` passes PHP-CS-Fixer, PHPStan, Deptrac with zero
violations, custom module boundaries, shell validation, migration/mapping
checks, all tests, current/history secret and private-data scans, production
artifact build/inspection, platform-requirement checks, and public-path probes.
`git diff --check`, `docker compose config --quiet`, and the locked Docker image
build also pass.

## Security, privacy, and review evidence

- Synthetic reserved `example.test` addresses and local Mailpit were the only
  recipient/mail values. No live SMTP credential, provider, production address,
  external account, DNS action, or production system was used.
- Raw addresses are absent from ordinary contact/suppression views, import
  reports, audit summaries, filenames, URLs, and log messages. Full disclosure
  paths are role-, recent-auth-, CSRF-, and audit-gated.
- Final diff review independently traced authorization, storage objects,
  sanitizer contexts, URL/image validation, audit ordering, import ownership,
  suppression rechecks, transaction/uniqueness behavior, cleanup, and Phase 6
  leakage. It repaired serialized preview/report state, private test-fixture
  cleanup, explicit recent authentication, and audit-before-disclosure export
  staging before the final aggregate run.
- Cloud-backed Ollama review was not used because the execution environment's
  data-sharing guard prohibited sending the repository's detailed security
  architecture externally. A local model produced no actionable evidence and
  its generic output was discarded; all accepted review conclusions were
  independently verified against code and tests.

## Manual actions and unresolved risks

- Operators must apply the forward-only migration, keep
  `EMAILER_PRIVATE_DIR` outside `public/` with restrictive ownership, and make
  the dedicated raster directory writable only by the application deployment
  identity. Administration remains HTTPS-only.
- Expired unfinished import cleanup is bounded and opportunistic on import
  activity; committed source deletion is immediate, while report/metadata
  cleanup follows the two-hour expiry path. Phase 8 still owns comprehensive
  retention automation and backup/restore.
- An authorized Operator can consume bounded CPU, disk, and database resources
  through repeated imports or image processing. Current per-file/row/dimension
  bounds limit individual work; distributed/resource quota policy remains an
  operations concern.
- Root/server-operator, database-administrator, and process-memory compromise
  remain outside the stated protection boundary. No migration rollback,
  production configuration, or deployment action was performed.

## Phase-boundary evidence

Phase 5 stops with recipient/content data ready and no sending path. No Phase 6
campaign, approval, scheduling, ledger, queue, worker, provider, retry,
circuit-breaker, progress, pause/resume/cancel, or live/fake SMTP delivery
implementation was started. Do not mark the draft pull request ready or merge
without Anton's explicit approval of the exact repository, phase, pull request,
and full current head SHA.
