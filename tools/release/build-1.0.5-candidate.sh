#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ne 3 ]; then
    printf 'Usage: %s SIGNING_PRIVATE_KEY PINNED_PUBLIC_KEY OUTPUT_DIRECTORY\n' "$0" >&2
    exit 2
fi

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
signing_key="$(realpath "$1")"
public_key="$(realpath "$2")"
output_directory="$(realpath "$3")"

case "$signing_key" in
    "$project_root"|"$project_root"/*)
        printf 'Signing private key must remain outside the repository.\n' >&2
        exit 2
        ;;
esac
test -f "$public_key"
test -d "$output_directory"
test -z "$(git -C "$project_root" status --porcelain --untracked-files=normal)"
test "$(git -C "$project_root" rev-parse --abbrev-ref HEAD)" != main

source_commit="$(git -C "$project_root" rev-parse HEAD)"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
temporary_root="$(mktemp -d)"
candidate_directory="$output_directory/emailer-next-1.0.5-candidate"
published=false
candidate_directory_created=false
cleanup() {
    if [ "$published" = false ] && [ "$candidate_directory_created" = true ] && [ -d "$candidate_directory" ]; then
        find "$candidate_directory" -mindepth 1 -depth -delete
        rmdir "$candidate_directory"
    fi
    find "$temporary_root" -mindepth 1 -depth -delete
    rmdir "$temporary_root"
}
trap cleanup EXIT
mkdir "$temporary_root/first" "$temporary_root/second"
mkdir -m 0755 "$candidate_directory"
candidate_directory_created=true

bash "$project_root/tools/artifact/build.sh"
for output in "$temporary_root/first" "$temporary_root/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" \
        --output="$output" \
        --version=1.0.5 \
        --source="$source_commit" \
        --minimum-source=1.0.4 \
        --supported-sources=1.0.4 \
        --minimum-schema=20261008000000 \
        --schema=20261013000000 \
        --rollback-schema-compatible=false \
        --signing-key="$signing_key" \
        --source-date-epoch="$source_epoch" >/dev/null
done

candidate=emailer-next-1.0.5.zip
for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$temporary_root/first/$candidate$suffix" "$temporary_root/second/$candidate$suffix"
done

for supported_source in 1.0.4; do
    php "$project_root/tools/release/verify-package.php" \
        --archive="$temporary_root/first/$candidate" \
        --public-key="$public_key" \
        --current-version="$supported_source" \
        --expected-version=1.0.5 \
        --expected-source="$source_commit" >/dev/null
    mkdir "$temporary_root/target-runtime-$supported_source"
    EMAILER_RUNTIME_DIR="$temporary_root/target-runtime-$supported_source" \
    EMAILER_RELEASE_PUBLIC_KEY_FILE="$public_key" \
    EMAILER_UPDATE_SOURCE_VERSION="$supported_source" \
    php "$project_root/build/artifact/bin/console" emailer:release:inspect \
        "$temporary_root/first/$candidate" >/dev/null
done

for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    install -m 0644 "$temporary_root/first/$candidate$suffix" "$candidate_directory/$candidate$suffix"
done
install -m 0644 "$public_key" "$candidate_directory/emailer-next-1.0.5-release.pub"
install -m 0644 "$project_root/tools/release/verify-package.php" "$candidate_directory/verify-package.php"
install -m 0644 "$project_root/src/Module/Release/Application/PackageInspector.php" "$candidate_directory/PackageInspector.php"

archive_sha256="$(sha256sum "$candidate_directory/$candidate" | cut -d' ' -f1)"
verifier_sha256="$(sha256sum "$candidate_directory/verify-package.php" | cut -d' ' -f1)"
inspector_sha256="$(sha256sum "$candidate_directory/PackageInspector.php" | cut -d' ' -f1)"
sed -e "s/@SOURCE_COMMIT@/$source_commit/g" -e "s/@SOURCE_EPOCH@/$source_epoch/g" -e "s/@ARCHIVE_SHA256@/$archive_sha256/g" -e "s/@VERIFIER_SHA256@/$verifier_sha256/g" -e "s/@INSPECTOR_SHA256@/$inspector_sha256/g" \
    "$project_root/tools/release/RELEASE-CANDIDATE-1.0.5.md.in" >"$candidate_directory/RELEASE-CANDIDATE-RECORD.md"
install -m 0644 "$project_root/docs/CLIENT-INSTALL-1.0.5.md" "$candidate_directory/CLIENT-INSTALL-1.0.5.md"
install -m 0644 "$project_root/docs/CLIENT-UPDATE-1.0.5.md" "$candidate_directory/CLIENT-UPDATE-1.0.5.md"
for guide in NEWSLETTER-GUIDE.md first-run-setup.md backup-restore.md; do
    install -m 0644 "$project_root/docs/$guide" "$candidate_directory/$guide"
done
published=true

printf '%s\n' "$candidate_directory/$candidate"
