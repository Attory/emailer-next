#!/usr/bin/env bash
set -euo pipefail
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"
app_image="${COMPOSE_PROJECT_NAME:-emailer-next}-app"
network="${COMPOSE_PROJECT_NAME:-emailer_next_ci}_edition"
state_root="$(mktemp -d /tmp/ripe106-ci-XXXXXX)"
chmod 0755 "$state_root"
if docker network inspect "$network" >/dev/null 2>&1; then
    printf 'Refusing an existing edition network.\n' >&2
    exit 1
fi
database_id="$(docker compose ps --quiet database)"
mailpit_id="$(docker compose ps --quiet mailpit)"
test -n "$database_id"
test -n "$mailpit_id"
docker network create --internal "$network" >/dev/null
auth_mailpit="${network}_auth_mailpit"
cleanup() {
    docker rm --force "$auth_mailpit" >/dev/null 2>&1 || true
    docker network disconnect --force "$network" "$database_id" >/dev/null 2>&1 || true
    docker network disconnect --force "$network" "$mailpit_id" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network connect --alias database "$network" "$database_id"
docker network connect --alias mailpit "$network" "$mailpit_id"
docker run --detach --name "$auth_mailpit" --network "$network" --network-alias auth-mailpit \
    -e MP_SMTP_AUTH_ACCEPT_ANY=true -e MP_SMTP_AUTH_ALLOW_INSECURE=true \
    -e MP_DISABLE_VERSION_CHECK=true axllent/mailpit:v1.30.5 >/dev/null
# Compose's vendor volume is not visible in independent docker-run rehearsals.
docker run --rm --user root --network none -v "$project_root:/workspace" "$app_image" \
    sh -euc 'mkdir -p /workspace/vendor; cp -a /opt/emailer-vendor/. /workspace/vendor/'
mkdir "$state_root/published-light"
curl --fail --silent --show-error --location https://github.com/Attory/emailer-light/releases/download/v1.0.0/emailer-light-1.0.0.zip --output "$state_root/published-light/emailer-light-1.0.0.zip"
curl --fail --silent --show-error --location https://github.com/Attory/emailer-light/releases/download/v1.0.0/emailer-light-1.0.0-source.zip --output "$state_root/published-light/emailer-light-1.0.0-source.zip"
curl --fail --silent --show-error --location https://raw.githubusercontent.com/Attory/emailer-light/b8705dc7ea8b42cea5af5ff89b4f8ed937da193a/release/emailer-light-release.pub --output "$state_root/published-light/emailer-light-release.pub"
printf '%s  %s\n' cfd9afb133c3bbfe7da6053aa2d121d325200c46f4db51e784699356d28d0e77 "$state_root/published-light/emailer-light-1.0.0.zip" 55932cc1f15f4d78553c00415c968eb1af976c49a088d2aaefcf7925d7dc43da "$state_root/published-light/emailer-light-1.0.0-source.zip" | sha256sum --check
source_commit="$(git rev-parse HEAD)"
source_epoch="$(git show -s --format=%ct HEAD)"
docker run --rm --user root --network none -v "$project_root:/tools:ro" -v "$state_root:/rehearsals" "$app_image" bash -euc '
    mkdir -m 0700 /rehearsals/test-keys
    mkdir -m 0755 /rehearsals/target-package
    php /tools/tools/release/generate-test-keypair.php /rehearsals/test-keys/signing.key /rehearsals/target-package/ripe.pub
    php /tools/tools/release/build-package.php --artifact=/tools/build/artifact --output=/rehearsals/target-package --version=1.0.6 --source="$1" --minimum-source=1.0.5 --supported-sources=1.0.5 --minimum-schema=20261013000000 --schema=20261015000000 --rollback-schema-compatible=false --signing-key=/rehearsals/test-keys/signing.key --source-date-epoch="$2" > /dev/null
' bash "$source_commit" "$source_epoch"
# Rebuild the accepted 1.0.5 tree in CI. Final local acceptance additionally uses
# the retained, originally signed and independently verified published archive.
mkdir -p "$state_root/source105" "$state_root/native105-package"
git archive 3927f17d73190b9e99861a8e3b34b572226665a1 | tar -x -C "$state_root/source105"
docker run --rm --user root --network none -v "$project_root:/tools:ro" -v "$state_root:/rehearsals" "$app_image" bash -euc '
    cp -a /opt/emailer-vendor /rehearsals/source105/vendor
    cp -a /app/assets/vendor /rehearsals/source105/assets/vendor
    bash /rehearsals/source105/tools/artifact/build.sh > /rehearsals/native105-build.log 2>&1
    php /tools/tools/release/build-package.php --artifact=/rehearsals/source105/build/artifact --output=/rehearsals/native105-package --version=1.0.5 --source=3927f17d73190b9e99861a8e3b34b572226665a1 --minimum-source=1.0.5 --supported-sources=1.0.5 --minimum-schema=20261013000000 --schema=20261013000000 --rollback-schema-compatible=false --signing-key=/rehearsals/test-keys/signing.key --source-date-epoch="$1" > /dev/null
' bash "$source_epoch"
export EMAILER_REHEARSAL_NETWORK="$network"
export EMAILER_REHEARSAL_APP_IMAGE="$app_image"
bash tools/upgrade/run-fresh-ripe-rehearsal.sh "$state_root/target-package/emailer-next-1.0.6.zip" "$state_root/target-package/ripe.pub" "$state_root" "$source_commit"
bash tools/upgrade/run-native-update-rehearsal.sh "$state_root/target-package/emailer-next-1.0.6.zip" "$state_root/target-package/ripe.pub" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" "$source_commit"
EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$state_root/target-package/emailer-next-1.0.6.zip" "$state_root/target-package/ripe.pub" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" "$source_commit"
bash tools/upgrade/run-light-rehearsal.sh "$state_root/target-package/emailer-next-1.0.6.zip" "$state_root/target-package/ripe.pub" "$state_root/published-light/emailer-light-1.0.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit"
EMAILER_REHEARSAL_MINIMAL=1 bash tools/upgrade/run-light-rehearsal.sh "$state_root/target-package/emailer-next-1.0.6.zip" "$state_root/target-package/ripe.pub" "$state_root/published-light/emailer-light-1.0.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit"
printf 'Independent signed-package edition rehearsals passed on this PHP/MariaDB matrix member.\n'
