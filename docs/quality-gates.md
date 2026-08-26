# CI and quality gates

**Status:** Phase 11 aggregate and signed update-candidate gates implemented.

One aggregate command, `composer check`, runs every executable current quality
gate. CI invokes the same version-pinned commands rather than maintaining a
second policy.

## Current commands and CI

The safe container path performs a clean lockfile install before the aggregate
gate:

```bash
./bin/bootstrap
```

With MariaDB and Mailpit already running, the aggregate command is:

```bash
docker compose run --rm app composer check
```

It runs, in order:

1. `composer validate --strict` and
   `composer audit --locked --abandoned=fail`;
2. PHP-CS-Fixer in check mode;
3. maximum-level PHPStan, Deptrac, and the project module-boundary check;
4. shell syntax checks;
5. Doctrine migration execution/up-to-date status and mapping validation;
6. PHPUnit, including the MariaDB and Mailpit integration tests;
7. production-artifact build, current/reachable-history secret scans,
   private-data scans, artifact inspection, and loopback exposure probes.

Phase 2 expands PHPUnit with ownership-code, proof-file edge, expiry, replay,
attempt ceiling, setup-mode, HTTPS, session-rotation, lock, reset, CLI, and
preflight coverage. Static architecture gates enforce the Setup module's inward
dependency direction.

Phase 3 adds cryptographic/key negative tests, complete external-key path and
ancestor validation, MariaDB setup persistence, idempotent Argon2id
Owner/MFA/recovery boundaries, exact plaintext/unauthenticated Mailpit evidence
with TLS/auth/live-host rejection, plaintext-output scanning, migration checks,
the end-to-end HTTPS wizard, and shared-lifecycle delayed-writer/finalizer plus
post-lock immutability coverage. The
aggregate command still includes every Phase 0–2 regression and the same
artifact/history/network controls.

The GitHub Actions workflow builds from the Dockerfile and tests:

| PHP | MariaDB |
| --- | --- |
| 8.4.24 | 10.11.18 |
| 8.4.24 | 11.8.8 |
| 8.5.9 | 10.11.18 |

It checks out full history without persisting credentials, starts only MariaDB
and Mailpit, runs the aggregate gate, then repeats PHPUnit on an internal Docker
network after proving a connection to `192.0.2.1:25` is impossible. CI has no
release or deployment step.

Phase 5 adds DOM/GD runtime requirements and the locked Symfony HTML Sanitizer
dependency. The unchanged aggregate gate now also exercises contact/import,
suppression, immutable template, sanitizer, asset, privacy, authorization, and
CSRF safety properties; no live transport or Phase 6 worker is introduced.

Phase 6 adds campaign/worker/concurrency/fake-transport coverage. Phase 7 adds
token codec, encrypted key-ring, expiry/rotation/replay, RFC header/body
snapshot, public firewall/cookie/cache/HTTPS, rate-limit, immediate suppression,
and post-unsubscribe delivery-race coverage. The aggregate artifact and
secret/private-data/history gates remain unchanged.

Phase 8 adds maximum-static validation of backup/recovery/update code plus
syntax validation for the offline package builder. PHPUnit covers Doctor gates,
encrypted backup/restore, recovery-key authentication, signed package
verification, housekeeping bounds, and the expanded admin authorization/CSRF
matrix. The artifact remains production-shaped; no package is published.

Phase 9 adds syntax and behavioral checks for every candidate tool, two-build
byte-for-byte reproducibility, SPDX license metadata, exact source/version and
pinned-key verification, a real immutable clean install, the signed RC update
and rollback/restore matrix, static Twig/CSS structural accessibility checks,
and the bounded 2,000-recipient reference load profile. The CI matrix covers PHP
8.4.24/8.5.9 and MariaDB 10.11.18/11.8.8. CI still cannot sign with or expose an
operator release key and never publishes a package.

Gitleaks 8.30.1 is checksum-pinned. It scans all reachable commits and the
current directory, including ignored/untracked dependencies, artifacts, and
generated logs. Reproducible framework/static-analysis caches are removed
before the directory scan to avoid duplicate third-party source. The
line-specific `.gitleaksignore` entries are reviewed dependency false positives;
no directory is blanket-allowlisted. The private-data check scans project-owned
source/history and generated output, permits only reserved example domains, and
does not classify public upstream author metadata in Composer lockfiles as
application recipient data.

## Stable gate order

1. Validate repository shape, clean-room attestations, and placeholder config.
2. Install exactly from `composer.lock` with scripts/plugins constrained.
3. Run `composer validate --strict` and dependency/license audit.
4. Run formatter/coding-standard checks.
5. Run architecture rules and PHPStan at the highest practical strictness.
6. Run unit, integration, functional, authorization/CSRF, browser, worker, and
   safety-property tests available in the current phase.
7. Validate Doctrine mappings, migrations, schema, templates, and links.
8. Scan the current tree and all reachable history for secrets and private keys.
9. Scan fixtures, snapshots, generated logs, diagnostics, and artifacts for PII
   and prohibited operational data.
10. Build the immutable production artifact from an allowlist and probe for
    public/private exposure.
11. Generate and verify checksum and SBOM only after every preceding gate.

No later step runs a release or deployment. Any failure blocks merge and
release.

## Phase 0 checks

The following commands are retained as historical Phase 0 evidence.

Phase 0 had no runtime or dependencies. The following read-only scans covered the
working tree, including ignored/untracked files except `.git`, and every
reachable commit. They print file names only on a finding, never matched
content.

```bash
phase0_secret_pattern='(AKIA|ASIA)[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{36,255}|github_pat_[A-Za-z0-9_]{20,255}|xox[baprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{35}|sk_(live|test)_[0-9A-Za-z]{16,}|SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}'
if rg --hidden --no-ignore -a -l -e "$phase0_secret_pattern" -g '!.git/**' .; then
    exit 1
else
    phase0_scan_status=$?
    if [ "$phase0_scan_status" -ne 1 ]; then
        exit "$phase0_scan_status"
    fi
fi
```

```bash
phase0_private_key_pattern='-----BEGIN (([A-Z0-9]+ )*PRIVATE KEY|PGP PRIVATE KEY BLOCK)-----'
if rg --hidden --no-ignore -a -l -e "$phase0_private_key_pattern" -g '!.git/**' .; then
    exit 1
else
    phase0_scan_status=$?
    if [ "$phase0_scan_status" -ne 1 ]; then
        exit "$phase0_scan_status"
    fi
fi
```

```bash
phase0_pii_pattern='[A-Z0-9.!#$%&*+/=?^_`{|}~-]+@[A-Z0-9][A-Z0-9.-]*\.[A-Z]{2,63}'
if rg --hidden --no-ignore -a -i -l -e "$phase0_pii_pattern" -g '!.git/**' .; then
    exit 1
else
    phase0_scan_status=$?
    if [ "$phase0_scan_status" -ne 1 ]; then
        exit "$phase0_scan_status"
    fi
fi
set -o pipefail
if rg --files --hidden --no-ignore -g '!.git/**' |
    rg -i '\.(csv|tsv|log|sql|sqlite|sqlite3|dump|backup|bak|p12|pfx|pem|key)$'; then
    exit 1
else
    phase0_scan_status=$?
    if [ "$phase0_scan_status" -ne 1 ]; then
        exit "$phase0_scan_status"
    fi
fi
```

```bash
phase0_secret_pattern='(AKIA|ASIA)[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{36,255}|github_pat_[A-Za-z0-9_]{20,255}|xox[baprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{35}|sk_(live|test)_[0-9A-Za-z]{16,}|SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}'
phase0_private_key_pattern='-----BEGIN (([A-Z0-9]+ )*PRIVATE KEY|PGP PRIVATE KEY BLOCK)-----'
phase0_pii_pattern='[A-Z0-9.!#$%&*+/=?^_`{|}~-]+@[A-Z0-9][A-Z0-9.-]*\.[A-Z]{2,63}'
while IFS= read -r phase0_commit; do
    if git grep -I -l -E -e "$phase0_secret_pattern" "$phase0_commit" --; then
        exit 1
    else
        phase0_scan_status=$?
        if [ "$phase0_scan_status" -ne 1 ]; then
            exit "$phase0_scan_status"
        fi
    fi
    if git grep -I -l -E -e "$phase0_private_key_pattern" "$phase0_commit" --; then
        exit 1
    else
        phase0_scan_status=$?
        if [ "$phase0_scan_status" -ne 1 ]; then
            exit "$phase0_scan_status"
        fi
    fi
    if git grep -I -i -l -E -e "$phase0_pii_pattern" "$phase0_commit" --; then
        exit 1
    else
        phase0_scan_status=$?
        if [ "$phase0_scan_status" -ne 1 ]; then
            exit "$phase0_scan_status"
        fi
    fi
done < <(git rev-list --all)

set -o pipefail
for phase0_pattern in \
    "$phase0_secret_pattern" \
    "$phase0_private_key_pattern" \
    "$phase0_pii_pattern"; do
    if git log --all --format='%B' | rg -a -i -l -e "$phase0_pattern"; then
        exit 1
    else
        phase0_scan_status=$?
        if [ "$phase0_scan_status" -ne 1 ]; then
            exit "$phase0_scan_status"
        fi
    fi
done
```

```bash
set -o pipefail
if git rev-list --objects --all | cut -d' ' -f2- |
    rg -i '\.(csv|tsv|log|sql|sqlite|sqlite3|dump|backup|bak|p12|pfx|pem|key)$'; then
    exit 1
else
    phase0_scan_status=$?
    if [ "$phase0_scan_status" -ne 1 ]; then
        exit "$phase0_scan_status"
    fi
fi
```

```bash
git fsck --full --strict --no-reflogs --unreachable
git diff --check
```

These high-confidence expressions were the transparent Phase 0 fallback before
a scanner dependency existed. They do not replace provenance review or manual
diff review. Phase 1 supersedes them with the pinned scan described above while
retaining full reachable-history coverage.

## Test environment isolation

The test kernel validates `MAILER_DSN` before framework boot. It accepts only
credential-free `smtp` to the Compose `mailpit` alias or an IP loopback address,
always on port 1025 and without options/path/fragment. Unit tests reject all
other shapes. CI adds the internal-network egress control described above.
Development Compose defaults to Mailpit and binds its UI to loopback.

CI uses synthetic, reserved-domain data only. The quality gate scans generated
logs and artifacts without printing matched values. No production hostname,
credential, or recipient is present.

## Artifact policy

The production-shaped artifact is assembled from
`tools/artifact/allowlist.txt`, installs locked production dependencies only,
compiles AssetMapper assets, and removes dependency documentation/tests. It
excludes `.git`, local environment files, project documentation, prompts, tests,
CI files, caches, logs, sessions, uploads, fixtures, backups, exports,
development dependencies/tools, and key material.

Phase 1 inspection compares the exact top-level allowlist, scans content,
rejects extra PHP entry points and public symlinks, starts the artifact on a
loopback PHP server, verifies the exact liveness body, and probes prohibited
paths for 404 responses. Representative Nginx and Apache profiles plus a
deterministic manifest, checksum, SPDX SBOM, signature, and release-candidate
production verification remain MVP release work in Phases 8–9; the Phase 1
artifact is not published or deployed.

After the Phase 9 MVP boundary, every phase gate must additionally build a
clean-install candidate and update the latest supported released fixture. It
must reject tampered, wrongly signed, downgraded, and schema-incompatible
packages and exercise interrupted-update plus compatible rollback or verified
restore behavior. These tests never publish or deploy the candidate.

## Gate ownership and exceptions

Each phase adds its tests to the aggregate command and records command/result
evidence in its phase report. New PHPStan baselines, skipped tests, audit
ignores, scanner allowlists, or artifact additions require line-level review and
a reason with an expiry/removal plan. Critical/high security findings and any
likely secret/private-data finding have no release waiver.
