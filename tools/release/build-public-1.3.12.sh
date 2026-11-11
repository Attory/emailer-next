#!/usr/bin/env bash
# New public fresh-install packaging; never overwrites historical signed assets.
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
cmp "$public_key" "$project_root/release/emailer-ripe-release.pub"
source_commit="$(git -C "$project_root" rev-parse HEAD)"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
candidate="$output/emailer-ripe-1.3.12-public"
test ! -e "$candidate"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/first" "$work/second"
bash "$project_root/tools/artifact/build.sh"
for target in "$work/first" "$work/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" --output="$target" --version=1.3.12 \
        --source="$source_commit" --minimum-source=1.3.11 --supported-sources=1.3.11 \
        --minimum-schema=20261025000000 --schema=20261025000000 --rollback-schema-compatible=false \
        --signing-key="$signing_key" --source-date-epoch="$source_epoch" >/dev/null
    git -C "$project_root" archive --format=zip --prefix=emailer-next-1.3.12-source/ \
        --output="$target/emailer-next-1.3.12-source.zip" "$source_commit"
    php -d memory_limit=256M "$project_root/installer/build-bundle.php" \
        --candidate="$target" --source="$source_commit" --version=1.3.12 \
        --public-key="$public_key" --signing-key="$signing_key" \
        --public-fresh-source="$target/emailer-next-1.3.12-source.zip" \
        --output="$target/emailer-ripe-1.3.12-public-setup.run"
    cp "$project_root/LICENSE" "$project_root/THIRD_PARTY_NOTICES.md" \
        "$project_root/PUBLIC-INSTALL.md" "$target/"
done
for file in "$work/first/"*; do
    cmp "$file" "$work/second/$(basename "$file")"
done
php "$project_root/tools/release/verify-package.php" \
    --archive="$work/first/emailer-next-1.3.12.zip" --public-key="$public_key" \
    --current-version=1.3.11 --expected-version=1.3.12 --expected-source="$source_commit"
(cd "$work/first" && sha256sum -- * >"$work/SHA256SUMS")
cp "$work/SHA256SUMS" "$work/first/SHA256SUMS"
php "$project_root/tools/release/sign-checksums.php" "$signing_key" \
    "$work/first/SHA256SUMS" "$work/first/SHA256SUMS.sig"
mkdir "$candidate"
cp "$work/first/"* "$candidate/"
printf 'Public fresh-install distribution prepared; historical releases unchanged.\n'
