#!/usr/bin/env bash
# Read the canonical stable release identity without booting the application.
EMAILER_REHEARSAL_TARGET_VERSION="$(awk '
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
export EMAILER_REHEARSAL_TARGET_VERSION
