#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 4 ]; then
    printf 'Usage: %s VERIFIED_ZIP PINNED_PUBLIC_KEY OUTPUT_DIR EXPECTED_COMMIT\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "$project_root/tools/upgrade/rehearsal-target.sh"
archive="$(realpath "$1")"
public_key="$(realpath "$2")"
output_directory="$(realpath "$3")"
expected_commit="$4"
network="${EMAILER_REHEARSAL_NETWORK:?An internal disposable network is required}"
app_image="${EMAILER_REHEARSAL_APP_IMAGE:-emailer-next-app:latest}"
test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
iteration="$(date +%s)_${RANDOM}"
case_root="$output_directory/ripe-fresh-$iteration"
mkdir -m 0755 "$case_root"
touch "$case_root/installation.log" "$case_root/verification.log"
run() {
    docker run --rm --user "${rehearsal_user:-root}" --network "$network" \
        -v "$project_root:/tools:ro" -v "$case_root:/rehearsal" \
        -v "$(dirname "$archive"):/package:ro" -v "$public_key:/pinned-ripe.pub:ro" \
        -e EMAILER_ISOLATED_REHEARSAL=1 \
        -e "EMAILER_REHEARSAL_TARGET_VERSION=$EMAILER_REHEARSAL_TARGET_VERSION" \
        -e "MARIADB_SERVER_VERSION=${MARIADB_SERVER_VERSION:-10.11.19-MariaDB}" \
        "$app_image" "$@"
}
run php /tools/tools/release/verify-package.php --archive=/package/emailer-next-${EMAILER_REHEARSAL_TARGET_VERSION}.zip --public-key=/pinned-ripe.pub --current-version=${EMAILER_REHEARSAL_TARGET_VERSION} --expected-version=${EMAILER_REHEARSAL_TARGET_VERSION} --expected-source="$expected_commit" > "$case_root/verification.log"
run php /tools/tools/upgrade/prepare-ripe-rehearsal.php "ripe106_${iteration}_fresh" /package/emailer-next-${EMAILER_REHEARSAL_TARGET_VERSION}.zip /pinned-ripe.pub ${EMAILER_REHEARSAL_TARGET_VERSION}
rehearsal_user=1000 run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /rehearsal/source-releases/${EMAILER_REHEARSAL_TARGET_VERSION}/bin/console emailer:setup:install --complete < /rehearsal/install-input' > "$case_root/installation.log" 2>&1
run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /tools/tools/upgrade/verify-fresh-ripe.php' >> "$case_root/verification.log"
printf 'Actual signed fresh Ripe installation completed: %s\n' "$case_root"
