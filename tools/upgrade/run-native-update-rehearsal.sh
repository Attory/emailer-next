#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 5 ]; then
    printf 'Usage: %s TARGET_ZIP TEST_PUBLIC_KEY VERIFIED_SOURCE_ZIP OUTPUT_DIR EXPECTED_COMMIT\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
archive="$(realpath "$1")"
public_key="$(realpath "$2")"
source_archive="$(realpath "$3")"
output_directory="$(realpath "$4")"
expected_commit="$5"
source_version="${EMAILER_REHEARSAL_NATIVE_SOURCE:-1.0.6}"
case "$source_version" in
    1.0.6) source_commit=e522f8ae007c4232b170f4717247378c4b897863 ;;
    1.1.0) source_commit=171d6aa658e3aca77c60fb9c7803303d8f4788cd ;;
    *) printf 'Unsupported native rehearsal predecessor.\n' >&2; exit 2 ;;
esac
network="${EMAILER_REHEARSAL_NETWORK:?An internal disposable network is required}"
app_image="${EMAILER_REHEARSAL_APP_IMAGE:-emailer-next-app:latest}"
test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
iteration="$(date +%s)_${RANDOM}"
case_root="$output_directory/ripe-update-$iteration"
mkdir -m 0755 "$case_root"
touch "$case_root/source-install.log" "$case_root/source-fixture.log" "$case_root/owner-enrollment.log" "$case_root/update.log" "$case_root/preservation.log"
run() {
    local options=()
    if [ -n "${rehearsal_detach:-}" ]; then options+=(--detach); fi
    if [ -n "${rehearsal_container:-}" ]; then options+=(--name "$rehearsal_container"); fi
    docker run --rm "${options[@]}" --user "${rehearsal_user:-root}" --network "$network" \
        -v "$project_root:/tools:ro" -v "$case_root:/rehearsal" \
        -v "$(dirname "$archive"):/package:ro" -v "$(dirname "$source_archive"):/native-source:ro" \
        -v "$public_key:/pinned-ripe.pub:ro" \
        -e EMAILER_ISOLATED_REHEARSAL=1 \
        -e EMAILER_REHEARSAL_NATIVE_TARGET=1 \
        -e "EMAILER_REHEARSAL_NATIVE_SOURCE=$source_version" \
        -e "MARIADB_SERVER_VERSION=${MARIADB_SERVER_VERSION:-10.11.19-MariaDB}" \
        "$app_image" "$@"
}
run php /tools/tools/release/verify-package.php --archive="/native-source/emailer-next-$source_version.zip" --public-key=/pinned-ripe.pub --current-version="$source_version" --expected-version="$source_version" --expected-source="$source_commit" --installed-source > "$case_root/preservation.log"
run php /tools/tools/release/verify-package.php --archive=/package/emailer-next-1.2.0.zip --public-key=/pinned-ripe.pub --current-version="$source_version" --expected-version=1.2.0 --expected-source="$expected_commit" >> "$case_root/preservation.log"
run php /tools/tools/upgrade/prepare-ripe-rehearsal.php "ripe106_${iteration}_native" "/native-source/emailer-next-$source_version.zip" /pinned-ripe.pub "$source_version"
rehearsal_user=1000 run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /rehearsal/source-releases/${EMAILER_REHEARSAL_NATIVE_SOURCE}/bin/console emailer:setup:install --complete < /rehearsal/install-input' > "$case_root/source-install.log" 2>&1
run bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/source-owner-runtime; set +a; php /tools/tools/upgrade/verify-owner-login.php "${EMAILER_REHEARSAL_NATIVE_SOURCE}"' > "$case_root/owner-enrollment.log" 2>&1
run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /tools/tools/upgrade/seed-native-ripe105.php; chmod -R a-w /rehearsal/source-releases/${EMAILER_REHEARSAL_NATIVE_SOURCE}' > "$case_root/source-fixture.log" 2>&1
rehearsal_user=1000 run bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/source-owner-runtime; set +a; php /rehearsal/source-releases/${EMAILER_REHEARSAL_NATIVE_SOURCE}/bin/console emailer:backup:create' >> "$case_root/source-fixture.log" 2>&1
run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /tools/tools/upgrade/observe-native-update.php before' >> "$case_root/preservation.log"
rehearsal_user=1000 run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; printf "%s\n%s\n" "Synthetic native recovery passphrase only" "Synthetic native recovery passphrase only" | php /rehearsal/source-releases/${EMAILER_REHEARSAL_NATIVE_SOURCE}/bin/console emailer:secrets:export-recovery-key' >> "$case_root/source-fixture.log" 2>&1
run php /tools/tools/upgrade/extract-rehearsal-target.php
if [ -n "${EMAILER_REHEARSAL_NATIVE_INTERRUPT:-}" ]; then
    case "$EMAILER_REHEARSAL_NATIVE_INTERRUPT" in
        journal) fault_table=release_operation ;;
        preparing) fault_table=backup_record ;;
        migrating) printf 'This pair has no schema mutation; use journal/preparing interruption tests.\n' >&2; exit 2 ;;
        *) printf 'Unsupported native interruption phase.\n' >&2; exit 2 ;;
    esac
    fault_lock="ripe106-native-lock-$iteration"
    fault_update="ripe106-native-update-$iteration"
    trap 'docker rm --force "$fault_update" "$fault_lock" >/dev/null 2>&1 || true' EXIT
    rehearsal_detach=1 rehearsal_container="$fault_lock" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/hold-rehearsal-table.php "$1"' bash "$fault_table" >/dev/null
    run bash -euc 'for iteration in {1..100}; do test ! -f /rehearsal/fault-lock-ready || exit 0; sleep 0.1; done; exit 1'
    rehearsal_detach=1 rehearsal_container="$fault_update" run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_UPDATE_SOURCE_VERSION=${EMAILER_REHEARSAL_NATIVE_SOURCE}; set +a; printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip' >/dev/null
    run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-native-interruption.php "$1"' bash "$EMAILER_REHEARSAL_NATIVE_INTERRUPT" >> "$case_root/preservation.log"
    docker kill "$fault_update" >/dev/null
    run php /tools/tools/upgrade/release-fault-lock.php
    for iteration in {1..100}; do
        if ! docker container inspect "$fault_lock" >/dev/null 2>&1; then break; fi
        sleep 0.1
    done
    if [ "$EMAILER_REHEARSAL_NATIVE_INTERRUPT" = migrating ]; then
        run rm /rehearsal/fault-lock-ready /rehearsal/release-fault-lock
        rehearsal_detach=1 rehearsal_container="$fault_lock" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/hold-rehearsal-table.php encrypted_secret' >/dev/null
        run bash -euc 'for iteration in {1..100}; do test ! -f /rehearsal/fault-lock-ready || exit 0; sleep 0.1; done; exit 1'
        rehearsal_detach=1 rehearsal_container="$fault_update" run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_UPDATE_SOURCE_VERSION=${EMAILER_REHEARSAL_NATIVE_SOURCE}; set +a; operation=$(php /tools/tools/upgrade/observe-native-interruption.php operation); printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip --recover-native-operation="$operation"' >/dev/null
        run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-native-interruption.php recovering' >> "$case_root/preservation.log"
        docker kill "$fault_update" >/dev/null
        run php /tools/tools/upgrade/release-fault-lock.php
        for iteration in {1..100}; do
            if ! docker container inspect "$fault_lock" >/dev/null 2>&1; then break; fi
            sleep 0.1
        done
    fi
    run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_UPDATE_SOURCE_VERSION=${EMAILER_REHEARSAL_NATIVE_SOURCE}; set +a; operation=$(php /tools/tools/upgrade/observe-native-interruption.php operation); printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip --recover-native-operation="$operation"; php /tools/tools/upgrade/observe-native-update.php restored; php /tools/tools/upgrade/lock-recovered-application.php native; printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip --recover-native-operation="$operation"; php /tools/tools/upgrade/observe-native-update.php restored; printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip; php /tools/tools/upgrade/observe-native-update.php after; if printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip --recover-native-operation="$operation"; then exit 1; fi; php /tools/tools/upgrade/observe-native-update.php after' > "$case_root/update.log" 2>&1
    printf 'Actual native interruption, checked recovery and forward retry completed: %s\n' "$case_root"
    exit 0
fi
if [ "${EMAILER_REHEARSAL_NATIVE_FAULT:-0}" = 1 ]; then
    fault_watcher="ripe106-native-fault-$iteration"
    trap 'docker rm --force "$fault_watcher" >/dev/null 2>&1 || true' EXIT
    rehearsal_detach=1 rehearsal_container="$fault_watcher" run php /tools/tools/upgrade/fail-after-activation.php >/dev/null
    if run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_UPDATE_SOURCE_VERSION=${EMAILER_REHEARSAL_NATIVE_SOURCE}; set +a; printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip' > "$case_root/update.log" 2>&1; then
        printf 'Native post-activation fault unexpectedly passed.\n' >&2
        exit 1
    fi
    run test -f /rehearsal/activation-fault-observed
    run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-native-update.php restored' >> "$case_root/preservation.log"
    printf 'Actual native update post-activation recovery completed: %s\n' "$case_root"
    exit 0
fi
run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_UPDATE_SOURCE_VERSION=${EMAILER_REHEARSAL_NATIVE_SOURCE}; set +a; printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip; php /tools/tools/upgrade/observe-native-update.php after' > "$case_root/update.log" 2>&1
if run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_UPDATE_SOURCE_VERSION=${EMAILER_REHEARSAL_NATIVE_SOURCE}; set +a; operation=$(php /tools/tools/upgrade/observe-native-interruption.php operation); printf "yes\n" | php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.2.0.zip --source-package=/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip --recover-native-operation="$operation"' >> "$case_root/update.log" 2>&1; then
    printf 'Completed native update permitted a recovery downgrade.\n' >&2
    exit 1
fi
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-native-update.php after' >> "$case_root/preservation.log"
printf 'Actual native Ripe %s to 1.2.0 update completed: %s\n' "$source_version" "$case_root"
