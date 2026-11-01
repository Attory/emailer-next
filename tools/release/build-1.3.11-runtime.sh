#!/usr/bin/env bash
# Minimal original-key runtime maintenance package; no installer or docs bundle.
set -euo pipefail
if [ "$#" -ne 3 ]; then
    printf 'Usage: %s SIGNING_PRIVATE_KEY PINNED_PUBLIC_KEY OUTPUT_DIRECTORY\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
signing_key="$(realpath "$1")"
public_key="$(realpath "$2")"
output="$(realpath "$3")"
case "$signing_key" in "$project_root"|"$project_root"/*) exit 2 ;; esac
test -z "$(git -C "$project_root" status --porcelain --untracked-files=normal)"
source_commit="$(git -C "$project_root" rev-parse HEAD)"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/first" "$work/second"
bash "$project_root/tools/artifact/build.sh"
for target in "$work/first" "$work/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" --output="$target" --version=1.3.11 \
        --source="$source_commit" --minimum-source=1.3.10 --supported-sources=1.3.10 \
        --minimum-schema=20261025000000 --schema=20261025000000 --rollback-schema-compatible=false \
        --signing-key="$signing_key" --source-date-epoch="$source_epoch" >/dev/null
done
archive=emailer-next-1.3.11.zip
for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$work/first/$archive$suffix" "$work/second/$archive$suffix"
done
php "$project_root/tools/release/verify-package.php" --archive="$work/first/$archive" \
    --public-key="$public_key" --current-version=1.3.10 --expected-version=1.3.11 --expected-source="$source_commit"
candidate="$output/emailer-next-1.3.11-runtime"
test ! -e "$candidate"
mkdir "$candidate"
cp "$work/first/"* "$candidate/"
printf 'Original-key runtime package ready. No full installer generated.\n'
