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
candidate_directory="$output_directory/emailer-next-0.9.0-candidate"
temporary_root="$(mktemp -d)"
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

case "$signing_key" in
    "$project_root"|"$project_root"/*) printf 'Signing key must remain outside the repository.\n' >&2; exit 2 ;;
esac
test -f "$public_key"
test -d "$output_directory"
test -z "$(find "$output_directory" -mindepth 1 -maxdepth 1 -print -quit)"
test -z "$(git -C "$project_root" status --porcelain --untracked-files=normal)"
test "$(git -C "$project_root" rev-parse --abbrev-ref HEAD)" != main

source_commit="$(git -C "$project_root" rev-parse HEAD)"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
mkdir "$temporary_root/first" "$temporary_root/second"
mkdir -m 0755 "$candidate_directory"
candidate_directory_created=true

bash "$project_root/tools/artifact/build.sh"
php -r '
    $path = $argv[1];
    $contents = file_get_contents($path);
    if (false === $contents) { exit(1); }
    $updated = str_replace("emailer.app_version: '\''1.0.0'\''", "emailer.app_version: '\''0.9.0'\''", $contents, $count);
    if (1 !== $count || false === file_put_contents($path, $updated)) { exit(1); }
' "$project_root/build/artifact/config/services.yaml"

for output in "$temporary_root/first" "$temporary_root/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" \
        --output="$output" \
        --version=0.9.0 \
        --source="$source_commit" \
        --minimum-source=0.1.0 \
        --supported-sources=0.1.0,0.2.0,0.3.0,0.4.0 \
        --minimum-schema=20260824010000 \
        --schema=20260828000000 \
        --rollback-schema-compatible=false \
        --signing-key="$signing_key" \
        --source-date-epoch="$source_epoch" >/dev/null
done

candidate=emailer-next-0.9.0.zip
for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$temporary_root/first/$candidate$suffix" "$temporary_root/second/$candidate$suffix"
    install -m 0644 "$temporary_root/first/$candidate$suffix" "$candidate_directory/$candidate$suffix"
done

for supported_source in 0.1.0 0.2.0 0.3.0 0.4.0; do
    php "$project_root/tools/release/verify-package.php" \
        --archive="$temporary_root/first/$candidate" \
        --public-key="$public_key" \
        --current-version="$supported_source" \
        --expected-version=0.9.0 \
        --expected-source="$source_commit" >/dev/null
done

install -m 0644 "$public_key" "$candidate_directory/emailer-next-0.9.0-release.pub"
install -m 0644 "$project_root/tools/release/verify-package.php" "$candidate_directory/verify-package.php"
install -m 0644 "$project_root/src/Module/Release/Application/PackageInspector.php" "$candidate_directory/PackageInspector.php"
sed "s/@SOURCE_COMMIT@/$source_commit/g" "$project_root/tools/release/install-0.9.0.sh" >"$candidate_directory/install-emailer-next-0.9.0.sh"
chmod 0755 "$candidate_directory/install-emailer-next-0.9.0.sh"

archive_sha256="$(sha256sum "$candidate_directory/$candidate" | cut -d' ' -f1)"
installer_sha256="$(sha256sum "$candidate_directory/install-emailer-next-0.9.0.sh" | cut -d' ' -f1)"
verifier_sha256="$(sha256sum "$candidate_directory/verify-package.php" | cut -d' ' -f1)"
inspector_sha256="$(sha256sum "$candidate_directory/PackageInspector.php" | cut -d' ' -f1)"
sed -e "s/@SOURCE_COMMIT@/$source_commit/g" -e "s/@SOURCE_EPOCH@/$source_epoch/g" -e "s/@ARCHIVE_SHA256@/$archive_sha256/g" -e "s/@INSTALLER_SHA256@/$installer_sha256/g" -e "s/@VERIFIER_SHA256@/$verifier_sha256/g" -e "s/@INSPECTOR_SHA256@/$inspector_sha256/g" \
    "$project_root/tools/release/RELEASE-CANDIDATE-0.9.0.md.in" >"$candidate_directory/RELEASE-CANDIDATE-RECORD.md"
install -m 0644 "$project_root/docs/CLIENT-INSTALL-0.9.0.md" "$candidate_directory/CLIENT-INSTALL-0.9.0.md"
install -m 0644 "$project_root/docs/0.9.0-INSTALLATION-WALKTHROUGH.md" "$candidate_directory/0.9.0-INSTALLATION-WALKTHROUGH.md"
install -m 0644 "$project_root/docs/accessibility-browser-support.md" "$candidate_directory/accessibility-browser-support.md"
published=true

printf '%s\n' "$candidate_directory/$candidate"
