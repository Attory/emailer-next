#!/usr/bin/env bash
set -euo pipefail
test "${EMAILER_ISOLATED_REHEARSAL:-0}" = 1
test "$(id -u)" = 0
test -f /rehearsal/runtime.env
test -f /rehearsal/updater.env
install -d -m0700 -o1000 -g1000 /rehearsal/native-live-runtime
# Before any delivery service starts, prove actual nonroot access and rejection
# under the authentic native operation hold; this is not root-only readiness.
env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/rehearsal/native-live-runtime LANG=C.UTF-8 EMAILER_ISOLATED_REHEARSAL=1 setpriv --reuid=1000 --regid=1000 --clear-groups bash -euc '
    set -a
    source /rehearsal/runtime.env
    set +a
    exec php /tools/installer/control-access.php /rehearsal/source-releases/1.3.6 "$EMAILER_PRIVATE_DIR"
' > /rehearsal/native-live-runtime/control-access.json
started="$(date +%s)"
env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/rehearsal/native-live-runtime LANG=C.UTF-8 EMAILER_ISOLATED_REHEARSAL=1 setpriv --reuid=1000 --regid=1000 --clear-groups bash -euc '
    set -a
    source /rehearsal/runtime.env
    export EMAILER_RUNTIME_DIR=/rehearsal/native-live-runtime
    set +a
    exec php /rehearsal/source-releases/1.3.6/bin/console messenger:consume async --time-limit=60 --no-interaction
' > /rehearsal/native-live-runtime/worker.log 2>&1 &
worker=$!
trap 'kill -TERM "$worker" 2>/dev/null || true; wait "$worker" || true' EXIT
env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/rehearsal/native-live-runtime LANG=C.UTF-8 EMAILER_ISOLATED_REHEARSAL=1 setpriv --reuid=1000 --regid=1000 --clear-groups bash -euc '
    set -a
    source /rehearsal/runtime.env
    export EMAILER_RUNTIME_DIR=/rehearsal/native-live-runtime
    set +a
    exec php /rehearsal/source-releases/1.3.6/bin/console emailer:scheduler:run --wait-for-configuration
' > /rehearsal/native-live-runtime/scheduler.log 2>&1 &
scheduler=$!
wait "$scheduler"
# Record the actual child process identities, never manufacture heartbeat rows.
php -r 'file_put_contents("/rehearsal/native-live-actors.json", json_encode(["format"=>"emailer-native-live-actors-v1", "started"=>(int)$argv[1], "actors"=>[$argv[2]=>"worker",$argv[3]=>"scheduler"]], JSON_THROW_ON_ERROR)); chmod("/rehearsal/native-live-actors.json",0600);' \
    "$started" "$(uname -n)-$worker" "$(uname -n)-$scheduler"
ready=0
for attempt in $(seq 1 30); do
    if env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/rehearsal/native-live-runtime LANG=C.UTF-8 EMAILER_ISOLATED_REHEARSAL=1 setpriv --reuid=1000 --regid=1000 --clear-groups bash -euc '
        set -a
        source /rehearsal/runtime.env
        export EMAILER_RUNTIME_DIR=/rehearsal/native-live-runtime
        set +a
        exec php /rehearsal/source-releases/1.3.6/bin/console emailer:health:verify-runtime --allow-operational-hold
    ' > /rehearsal/native-live-runtime/verification.log 2>&1; then ready=1; break; fi
    kill -0 "$worker"
    sleep 0.2
done
test "$ready" = 1
set -a
source /rehearsal/updater.env
export EMAILER_UPDATE_SOURCE_VERSION="${EMAILER_REHEARSAL_NATIVE_SOURCE}"
set +a
operation="$(php /tools/tools/upgrade/observe-native-interruption.php operation)"
for attempt in 1 2; do
    php /rehearsal/target/bin/console emailer:release:update /package/emailer-next-1.3.6.zip \
        --source-package="/native-source/emailer-next-${EMAILER_REHEARSAL_NATIVE_SOURCE}.zip" \
        --finish-native-operation="$operation" --approved-target=1.3.6 --no-interaction
done
EMAILER_REHEARSAL_NATIVE_LIVE_EVIDENCE=1 php /tools/tools/upgrade/observe-native-update.php after
