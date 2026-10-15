#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 2 ]; then
    printf 'Usage: %s APP_IMAGE EMPTY_OUTPUT_DIRECTORY\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
app_image="$1"
output="$(realpath "$2")"
test -d "$output"
test -z "$(find "$output" -mindepth 1 -print -quit)"
mkdir "$output/locks"
# Exact accepted first-party predecessors used by Phase8ReleaseUpdateTest.
for commit in 7d08cc6e20042fc448657fd87ea6722d91c60e7c af0d994d32e47d49df6654d6000c42891f8804f2 765a7d1e1a3565b65b186c683bc39a28b3d61466 c3c5d0ba5373e46f42183c3fc31264316f512afd eabd9abb153d1ea91c35b060422db04bfcbc2d7a 4eca16bc7b212a8d35bfe1b6f05f199443a611df; do
    mkdir "$output/locks/$commit"
    for file in composer.json composer.lock; do
        git -C "$project_root" show "$commit:$file" > "$output/locks/$commit/$file"
    done
done
# Only public dependency metadata and archives are mounted, never application
# source, signing keys, credentials or private installation/recovery state.
docker run --rm --user root --network bridge -v "$output:/prepared" "$app_image" bash -euc '
    mkdir /prepared/cache
    if [ -d /home/emailer/.composer/cache/files ]; then
        cp -a /home/emailer/.composer/cache/files /prepared/cache/files
    fi
    for source in /prepared/locks/*; do
        COMPOSER_CACHE_DIR=/prepared/cache composer --working-dir="$source" install --download-only --no-scripts --no-plugins --no-interaction --no-progress
    done
    mkdir /prepared/downloads
    cp -a /prepared/cache/files /prepared/downloads/files
    chown -R 1000:1000 /prepared/downloads
'
printf 'Historical locked dependency archives prepared; application tests remain isolated.\n'
