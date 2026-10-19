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
printf '%s  %s\n' cfd9afb133c3bbfe7da6053aa2d121d325200c46f4db51e784699356d28d0e77 "$state_root/published-light/emailer-light-1.0.0.zip" 55932cc1f15f4d78553c00415c968eb1af976c49a088d2aaefcf7925d7dc43da "$state_root/published-light/emailer-light-1.0.0-source.zip" 1279a3202e656378c18b0b303c7605ebd9a2aadf3cc7de548306a0cdab42b6a8 "$state_root/published-light/emailer-light-release.pub" | sha256sum --check
source_commit="$(git rev-parse HEAD)"
source_epoch="$(git show -s --format=%ct HEAD)"
# Runtime identities always match the source tree: never relabel HEAD as1.0.6.
docker run --rm --user root --network none -v "$project_root:/tools:ro" -v "$state_root:/rehearsals" "$app_image" bash -euc '
    mkdir -m0700 /rehearsals/test-keys
    mkdir -m0755 /rehearsals/target-package
    php /tools/tools/release/generate-test-keypair.php /rehearsals/test-keys/signing.key /rehearsals/target-package/ripe.pub
    php /tools/tools/release/build-package.php --artifact=/tools/build/artifact --output=/rehearsals/target-package --version=1.2.3 --source="$1" --minimum-source=1.0.6 --supported-sources=1.0.6,1.1.0,1.2.1,1.2.2 --minimum-schema=20261015000000 --schema=20261015000000 --rollback-schema-compatible=true --signing-key=/rehearsals/test-keys/signing.key --source-date-epoch="$2" >/dev/null
' bash "$source_commit" "$source_epoch"
for version in 105 106 110 121 122; do
    case "$version" in
        105) historical_commit=3927f17d73190b9e99861a8e3b34b572226665a1 ;;
        106) historical_commit=e522f8ae007c4232b170f4717247378c4b897863 ;;
        110) historical_commit=171d6aa658e3aca77c60fb9c7803303d8f4788cd ;;
        121) historical_commit=adb8b71f913519da580b389e3a5a11c243dabbb4 ;;
        122) historical_commit=25e703c14b3f9ac6b864e3ad1f7e35b3cc2b74d8 ;;
    esac
    mkdir -p "$state_root/source$version" "$state_root/native$version-package"
    git archive "$historical_commit" | tar -x -C "$state_root/source$version"
    # Release archives exclude tests. The historical rehearsal tools still need
    # their own exact fixture; never substitute today's fixture into old code.
    if [ "$version" != 105 ]; then
        mkdir -p "$state_root/source$version/tests/Fixtures"
        git show "$historical_commit:tests/Fixtures/upgrade-light-1.0.0.json" >"$state_root/source$version/tests/Fixtures/upgrade-light-1.0.0.json"
    fi
    # Connected preparation sees only historical public dependencies, no keys or runtime state.
    docker run --rm --user root --network bridge -v "$state_root/source$version:/source" "$app_image" bash -euc '
        cp -a /opt/emailer-vendor /source/vendor
        cp -a /app/assets/vendor /source/assets/vendor
        composer --working-dir=/source install --prefer-dist --no-scripts --no-interaction --no-progress
    '
    docker run --rm --user root --network none -v "$project_root:/tools:ro" -v "$state_root:/rehearsals" "$app_image" bash -euc '
        bash "/rehearsals/source$1/tools/artifact/build.sh" >"/rehearsals/native$1-build.log" 2>&1 || { tail -n100 "/rehearsals/native$1-build.log"; exit 1; }
        if [ "$1" = 105 ]; then release=1.0.5; schema=20261013000000; elif [ "$1" = 106 ]; then release=1.0.6; schema=20261015000000; elif [ "$1" = 110 ]; then release=1.1.0; schema=20261015000000; elif [ "$1" = 121 ]; then release=1.2.1; schema=20261015000000; else release=1.2.2; schema=20261015000000; fi
        php /tools/tools/release/build-package.php --artifact="/rehearsals/source$1/build/artifact" --output="/rehearsals/native$1-package" --version="$release" --source="$2" --minimum-source=1.0.5 --supported-sources=1.0.5 --minimum-schema=20261013000000 --schema="$schema" --rollback-schema-compatible=false --signing-key=/rehearsals/test-keys/signing.key --source-date-epoch="$3" >/dev/null
    ' bash "$version" "$historical_commit" "$source_epoch"
    cp "$state_root/target-package/ripe.pub" "$state_root/native$version-package/ripe.pub"
done
export EMAILER_REHEARSAL_NETWORK="$network"
export EMAILER_REHEARSAL_APP_IMAGE="$app_image"
# Preserve complete historical failure/partial-DDL recovery coverage with the
# original reviewed1.0.6 code, tools and artifact. Test signatures are synthetic.
legacy="$state_root/source106/tools/upgrade"
legacy_target="$state_root/native106-package/emailer-next-1.0.6.zip"
legacy_key="$state_root/native106-package/ripe.pub"
bash "$legacy/run-native-update-rehearsal.sh" "$legacy_target" "$legacy_key" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" e522f8ae007c4232b170f4717247378c4b897863
EMAILER_REHEARSAL_NATIVE_FAULT=1 bash "$legacy/run-native-update-rehearsal.sh" "$legacy_target" "$legacy_key" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" e522f8ae007c4232b170f4717247378c4b897863
for phase in journal preparing migrating; do
    EMAILER_REHEARSAL_NATIVE_INTERRUPT="$phase" bash "$legacy/run-native-update-rehearsal.sh" "$legacy_target" "$legacy_key" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" e522f8ae007c4232b170f4717247378c4b897863
done
# Current schema-preserving update: real process interruption before checkpoint,
# plus post-activation failure. There is no intermediate1.1 ALTER to fabricate.
target="$state_root/target-package/emailer-next-1.2.3.zip"
key="$state_root/target-package/ripe.pub"
bash tools/upgrade/run-fresh-ripe-rehearsal.sh "$target" "$key" "$state_root" "$source_commit"
bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$legacy_target" "$state_root" "$source_commit"
EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$legacy_target" "$state_root" "$source_commit"
for phase in journal preparing; do
    EMAILER_REHEARSAL_NATIVE_INTERRUPT="$phase" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$legacy_target" "$state_root" "$source_commit"
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root" "$source_commit" ;;
        fault) EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root" "$source_commit" ;;
        *) EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native121-package/emailer-next-1.2.1.zip" "$state_root" "$source_commit" ;;
        fault) EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.1 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native121-package/emailer-next-1.2.1.zip" "$state_root" "$source_commit" ;;
        *) EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.1 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native121-package/emailer-next-1.2.1.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.2 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native122-package/emailer-next-1.2.2.zip" "$state_root" "$source_commit" ;;
        fault) EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.2 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native122-package/emailer-next-1.2.2.zip" "$state_root" "$source_commit" ;;
        *) EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.2 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native122-package/emailer-next-1.2.2.zip" "$state_root" "$source_commit" ;;
    esac
done
# Light's production pin is never substituted to accommodate a CI signer.
# Publish the fully accepted Light release before enabling the corresponding
# private Ripe release: public release sidecars are authenticated by current code.
for light_version in 1.1.0 1.2.0 1.2.1 1.2.2 1.2.3; do
    for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
        curl --fail --silent --show-error --location "https://github.com/Attory/emailer-light/releases/download/v$light_version/emailer-light-$light_version.zip$suffix" --output "$state_root/published-light/emailer-light-$light_version.zip$suffix"
    done
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) bash tools/upgrade/run-converted-native-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root/published-light/emailer-light-1.1.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit" "$state_root/source110" ;;
        fault) EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-converted-native-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root/published-light/emailer-light-1.1.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit" "$state_root/source110" ;;
        *) EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-converted-native-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root/published-light/emailer-light-1.1.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit" "$state_root/source110" ;;
    esac
done
for light_version in 1.0.0 1.1.0 1.2.0 1.2.1 1.2.2 1.2.3; do
    EMAILER_REHEARSAL_LIGHT_VERSION="$light_version" bash tools/upgrade/run-light-rehearsal.sh "$target" "$key" "$state_root/published-light/emailer-light-$light_version.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit"
    EMAILER_REHEARSAL_LIGHT_VERSION="$light_version" EMAILER_REHEARSAL_MINIMAL=1 bash tools/upgrade/run-light-rehearsal.sh "$target" "$key" "$state_root/published-light/emailer-light-$light_version.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit"
done
printf 'Historical and current signed-package edition rehearsals passed on this PHP/MariaDB matrix member.\n'
