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
candidate_directory="$output_directory/emailer-next-0.2.0-candidate"
published=false
cleanup() {
    if [ "$published" = false ] && [ -d "$candidate_directory" ]; then
        find "$candidate_directory" -mindepth 1 -depth -delete
        rmdir "$candidate_directory"
    fi
    find "$temporary_root" -mindepth 1 -depth -delete
    rmdir "$temporary_root"
}
trap cleanup EXIT
mkdir "$temporary_root/first" "$temporary_root/second"
mkdir -m 0755 "$candidate_directory"

bash "$project_root/tools/artifact/build.sh"
for output in "$temporary_root/first" "$temporary_root/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" \
        --output="$output" \
        --version=0.2.0 \
        --source="$source_commit" \
        --minimum-source=0.1.0 \
        --schema=20260826000000 \
        --rollback-schema-compatible=false \
        --signing-key="$signing_key" \
        --source-date-epoch="$source_epoch" >/dev/null
done

candidate=emailer-next-0.2.0.zip
for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$temporary_root/first/$candidate$suffix" "$temporary_root/second/$candidate$suffix"
done

php "$project_root/tools/release/verify-package.php" \
    --archive="$temporary_root/first/$candidate" \
    --public-key="$public_key" \
    --current-version=0.1.0 \
    --expected-version=0.2.0 \
    --expected-source="$source_commit" >/dev/null

for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    install -m 0644 "$temporary_root/first/$candidate$suffix" "$candidate_directory/$candidate$suffix"
done
install -m 0644 "$public_key" "$candidate_directory/emailer-next-0.2.0-release.pub"

archive_sha256="$(sha256sum "$candidate_directory/$candidate" | cut -d' ' -f1)"
cat >"$candidate_directory/RELEASE-CANDIDATE-RECORD.md" <<EOF
# Emailer Next 0.2.0 release-candidate record

- Source commit: \`$source_commit\`
- Source date epoch: \`$source_epoch\`
- Archive SHA-256: \`$archive_sha256\`
- Schema: \`20260826000000\`
- Minimum source: \`0.1.0\`
- Rollback: schema-incompatible pointer rollback is prohibited; restore the verified pre-update backup
- Reproducibility: two independent builds and all five package outputs were byte-identical
- Signature: detached Ed25519 signature verified against the separately supplied pinned public key
- Publication/deployment: not performed
EOF
published=true

printf '%s\n' "$candidate_directory/$candidate"
