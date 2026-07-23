# CI and quality gates

**Status:** Phase 0 design; CI implementation begins in Phase 1

One aggregate command, `composer check`, will run the complete local quality
pipeline once Composer exists. CI invokes the same version-pinned commands
rather than maintaining a second policy.

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

Phase 0 has no runtime or dependencies. The following read-only scans cover the
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

These high-confidence expressions are a transparent Phase 0 fallback because
no scanner dependency is present yet. They do not replace provenance review or
manual diff review. Phase 1 must pin a maintained secret scanner, scan generic
credential assignments and entropy safely, prevent secret values from being
printed, and retain full reachable-history coverage. Scanner configuration may
allowlist exact synthetic placeholders, never broad directories.

## Test environment isolation

Phase 1 must make Mailpit the only permitted SMTP target in development/CI and
add a negative test proving arbitrary hosts cannot be contacted. CI should
enforce egress denial or a narrow allowlist at the job/network layer in
addition to application configuration. Test recipient rewriting is not a
substitute for network isolation.

CI uses synthetic, reserved-domain data only. Logs and snapshots are scanned
after tests even when tests fail. Secret environment values are masked and
never echoed for diagnostics.

## Artifact policy

The production artifact is assembled from an explicit runtime allowlist. It
excludes at minimum `.git`, local environment files, documentation not needed
at runtime, prompts, tests, CI files, caches, logs, sessions, temporary uploads,
fixtures, backups, exports, development dependencies/tools, and the master key.
An exposure test starts representative Nginx and Apache configurations and
probes prohibited paths before artifact acceptance.

## Gate ownership and exceptions

Each phase adds its tests to the aggregate command and records command/result
evidence in its phase report. New PHPStan baselines, skipped tests, audit
ignores, scanner allowlists, or artifact additions require line-level review and
a reason with an expiry/removal plan. Critical/high security findings and any
likely secret/private-data finding have no release waiver.
