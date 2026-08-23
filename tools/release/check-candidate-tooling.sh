#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary_root="$(mktemp -d)"
cleanup() {
    chmod -R u+w "$temporary_root"
    find "$temporary_root" -mindepth 1 -depth -delete
    rmdir "$temporary_root"
}
trap cleanup EXIT

mkdir "$temporary_root/first" "$temporary_root/second"
php "$project_root/tools/release/generate-test-keypair.php" "$temporary_root/signing.key" "$temporary_root/release.pub"

source_commit="$(git -C "$project_root" rev-parse HEAD)"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
for output in "$temporary_root/first" "$temporary_root/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" \
        --output="$output" \
        --version=0.1.0 \
        --source="$source_commit" \
        --minimum-source=0.1.0-rc.1 \
        --schema=20260824010000 \
        --rollback-schema-compatible=true \
        --signing-key="$temporary_root/signing.key" \
        --source-date-epoch="$source_epoch" >/dev/null
done

for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$temporary_root/first/emailer-next-0.1.0.zip$suffix" "$temporary_root/second/emailer-next-0.1.0.zip$suffix"
done

php "$project_root/tools/release/verify-package.php" \
    --archive="$temporary_root/first/emailer-next-0.1.0.zip" \
    --public-key="$temporary_root/release.pub" \
    --current-version=0.1.0-rc.1 \
    --expected-version=0.1.0 \
    --expected-source="$source_commit" >/dev/null

EMAILER_RELEASE_PUBLIC_KEY_FILE="$temporary_root/release.pub" \
EMAILER_RELEASES_DIR="$temporary_root/releases" \
php "$project_root/bin/console" emailer:release:install \
    "$temporary_root/first/emailer-next-0.1.0.zip" >/dev/null
test -f "$temporary_root/releases/0.1.0/bin/console"
test ! -e "$temporary_root/releases/current"
test "$(stat -c '%a' "$temporary_root/releases/0.1.0/bin/console")" = 444

printf 'Deterministic signed candidate tooling and clean install verified.\n'
