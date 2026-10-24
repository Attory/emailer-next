#!/usr/bin/env bash
set -euo pipefail
# Called only AFTER the complete outer rehearsal (including recovery) passed.
# Keep a verified full archive; bound duplicate runtime files/inodes on CI.
test "${EMAILER_ISOLATED_REHEARSAL:-0}" = 1
test "${EMAILER_REHEARSAL_CASE_COMPLETED:-0}" = 1
test "$EUID" = 0
test "$(realpath /rehearsals)" = /rehearsals
test ! -L /rehearsals
umask 077
if [ -e /rehearsals/passed-evidence ]; then
    test -d /rehearsals/passed-evidence
    test ! -L /rehearsals/passed-evidence
    test "$(stat -c %u:%a /rehearsals/passed-evidence)" = 0:700
else
    mkdir -m0700 /rehearsals/passed-evidence
fi
for directory in /rehearsals/light-* /rehearsals/ripe-update-* /rehearsals/ripe-fresh-* /rehearsals/converted-native-*; do
    test ! -L "$directory"
    if [ ! -d "$directory" ]; then continue; fi
    name="$(basename "$directory")"
    [[ "$name" =~ ^(light|ripe-update|ripe-fresh|converted-native)-[0-9]+_[0-9]+$ ]]
    test "$(realpath "$directory")" = "/rehearsals/$name"
    archive="/rehearsals/passed-evidence/$name.tar.gz"
    test ! -e "$archive"
    test ! -L "$archive"
    test ! -e "$archive.sha256"
    test ! -L "$archive.sha256"
    tar --create --gzip --file="$archive" --directory=/rehearsals -- "$name"
    gzip --test "$archive"
    tar --compare --gzip --file="$archive" --directory=/rehearsals
    sha256sum "$archive" > "$archive.sha256"
    # Never compact failed/in-progress operations: the caller only invokes us
    # after all assertions of the complete independent outer case succeeded.
    rm -rf -- "$directory"
    printf 'Completed rehearsal evidence archived, compared and checksummed: %s\n' "$name"
done
