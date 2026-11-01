#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Read the canonical stable release identity without booting the application.
target_version="$(awk '
    /^[[:space:]]*emailer[.]app_version[[:space:]]*:/ {
        count++
        if ($0 !~ /^[[:space:]]*emailer[.]app_version:[[:space:]]*\047(0|[1-9][0-9]*)[.](0|[1-9][0-9]*)[.](0|[1-9][0-9]*)\047[[:space:]]*$/) invalid = 1
        split($0, parts, "\047")
        version = parts[2]
    }
    END {
        if (count != 1 || invalid) exit 1
        print version
    }
' "$project_root/config/services.yaml")" || {
    printf 'Expected exactly one canonical emailer.app_version in config/services.yaml.\n' >&2
    exit 1
}
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
        --version="$target_version" \
        --source="$source_commit" \
        --minimum-source=1.0.6 \
        --supported-sources=1.0.6,1.1.0,1.2.1,1.2.2,1.3.0,1.3.1,1.3.2,1.3.3,1.3.4,1.3.5 \
        --minimum-schema=20261015000000 \
        --schema=20261025000000 \
        --rollback-schema-compatible=false \
        --signing-key="$temporary_root/signing.key" \
        --source-date-epoch="$source_epoch" >/dev/null
done

for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$temporary_root/first/emailer-next-$target_version.zip$suffix" "$temporary_root/second/emailer-next-$target_version.zip$suffix"
done

mkdir "$temporary_root/standalone-verifier"
install -m 0644 "$project_root/tools/release/verify-package.php" "$temporary_root/standalone-verifier/verify-package.php"
install -m 0644 "$project_root/src/Module/Release/Application/PackageInspector.php" "$temporary_root/standalone-verifier/PackageInspector.php"

for supported_source in 1.0.6 1.1.0 1.2.1 1.2.2 1.3.0 1.3.1 1.3.2 1.3.3 1.3.4 1.3.5; do
    php "$temporary_root/standalone-verifier/verify-package.php" \
        --archive="$temporary_root/first/emailer-next-$target_version.zip" \
        --public-key="$temporary_root/release.pub" \
        --current-version="$supported_source" \
        --expected-version="$target_version" \
        --expected-source="$source_commit" >/dev/null
    mkdir "$temporary_root/target-runtime-$supported_source"
    EMAILER_RUNTIME_DIR="$temporary_root/target-runtime-$supported_source" \
    EMAILER_RELEASE_PUBLIC_KEY_FILE="$temporary_root/release.pub" \
    EMAILER_UPDATE_SOURCE_VERSION="$supported_source" \
    php "$project_root/build/artifact/bin/console" emailer:release:inspect \
        "$temporary_root/first/emailer-next-$target_version.zip" >/dev/null
done

EMAILER_RELEASE_PUBLIC_KEY_FILE="$temporary_root/release.pub" \
EMAILER_RELEASES_DIR="$temporary_root/releases" \
EMAILER_UPDATE_SOURCE_VERSION="$target_version" \
php "$project_root/bin/console" emailer:release:install \
    "$temporary_root/first/emailer-next-$target_version.zip" >/dev/null
test -f "$temporary_root/releases/$target_version/bin/console"
test ! -e "$temporary_root/releases/current"
test "$(stat -c '%a' "$temporary_root/releases/$target_version/bin/console")" = 444

printf 'Deterministic signed %s tooling, supported-source verification, and clean install verified.\n' "$target_version"
