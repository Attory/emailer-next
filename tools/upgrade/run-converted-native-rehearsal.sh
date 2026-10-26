#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 8 ]; then
    printf 'Usage: %s TARGET_ZIP RIPE_KEY RIPE110_ZIP LIGHT110_ZIP LIGHT_KEY OUTPUT_DIR TARGET_COMMIT HISTORICAL110_ROOT\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
archive="$(realpath "$1")"
key="$(realpath "$2")"
source="$(realpath "$3")"
light="$(realpath "$4")"
light_key="$(realpath "$5")"
output="$(realpath "$6")"
commit="$7"
historical="$(realpath "$8")"
test -f "$historical/tools/upgrade/run-light-rehearsal.sh"
test "$(basename "$source")" = emailer-next-1.1.0.zip
test "$(basename "$light")" = emailer-light-1.1.0.zip
log="$(mktemp "$output/converted-source-XXXXXX.log")"
# Create a real historical conversion including campaign-owned recipients,
# encrypted SMTP/token contexts, tracking, MFA and recovery history.
historical_directory="$(mktemp -d "$output/historical110-actors-XXXXXXXX")"
historical_runner="$historical_directory/run.sh"
python3 "$project_root/tools/ci/prepare-historical-rehearsal.py" "$historical/tools/upgrade/run-light-rehearsal.sh" "$historical_runner"
EMAILER_REHEARSAL_PHASE_OBSERVER="$project_root/tools/upgrade/observe-rehearsal-phase.php" EMAILER_HISTORICAL_REHEARSAL_ROOT="$historical" EMAILER_REHEARSAL_ACTOR_TOOL="$project_root/tools/ci/rehearsal-actors.py" EMAILER_REHEARSAL_LIGHT_VERSION=1.1.0 bash "$historical_runner" "$source" "$key" "$light" "$light_key" "$output" 171d6aa658e3aca77c60fb9c7803303d8f4788cd >"$log" 2>&1
case_root="$(sed -n 's/^Packaged synthetic Light transition completed: //p' "$log")"
test -n "$case_root"
# The historical acceptance script ends by restoring its earlier native backup,
# which predates Owner MFA enrollment. Enroll normally through that historical
# application's authenticated UI before capturing this update's MFA baseline.
# No password, source account or existing MFA material is reset.
docker run --rm --user root --network "${EMAILER_REHEARSAL_NETWORK:?}" \
    -v "$historical:/tools:ro" -v "$case_root:/rehearsal" -v "$key:/package/ripe.pub:ro" \
    -e EMAILER_ISOLATED_REHEARSAL=1 "${EMAILER_REHEARSAL_APP_IMAGE:-emailer-next-app:latest}" \
    bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/converted-native-source-owner-runtime; set +a; php /tools/tools/upgrade/verify-owner-login.php' >>"$log" 2>&1
EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 EMAILER_REHEARSAL_NATIVE_CONVERTED_ROOT="$case_root" bash "$project_root/tools/upgrade/run-native-update-rehearsal.sh" "$archive" "$key" "$source" "$output" "$commit"
