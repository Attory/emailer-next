#!/usr/bin/env bash
set -Eeuo pipefail
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"
app_image="${COMPOSE_PROJECT_NAME:-emailer-next}-app"
network="${COMPOSE_PROJECT_NAME:-emailer_next_ci}_edition"
state_root="$(mktemp -d /tmp/ripe106-ci-XXXXXX)"
chmod 0755 "$state_root"

rehearsal_failure() {
    local status="$1" line="$2" directory log
    trap - ERR
    printf 'Independent rehearsal failed: script line %s, status %s. Raw private logs are withheld.\n' "$line" "$status" >&2
    df -Pk "$state_root" >&2 || true
    df -Pi "$state_root" >&2 || true
    for directory in "$state_root"/light-*; do
        if [ ! -d "$directory" ] || [ -L "$directory" ]; then continue; fi
        if [ -f "$directory/rehearsal-phase" ] && [ ! -L "$directory/rehearsal-phase" ]; then
            printf 'Case %s phase: ' "$(basename "$directory")" >&2
            grep -Ex '(source-install|source-fixture|transition|legacy-clone-setup|legacy-clone-restore|legacy-clone-inspect|legacy-clone-execute|postaccept-smtp|native-backup|complete)' "$directory/rehearsal-phase" >&2 || true
        fi
        for log in "$directory/source-install.log" "$directory/recovery.log" "$directory/acceptance.log"; do
            if [ ! -f "$log" ] || [ -L "$log" ]; then continue; fi
            # Only fixed location/SQLSTATE captures: never dump an exception
            # message, query parameters, environment, key or credential value.
            sed -nE 's/.*In ([A-Za-z_][A-Za-z0-9_]*\.php) line ([0-9]+):.*/location=\1:\2/p; s/.*SQLSTATE\[([A-Z0-9]{5})\].*/sqlstate=\1/p' "$log" | sort -u | head -12 >&2 || true
        done
    done
    return "$status"
}
trap 'rehearsal_failure "$?" "$LINENO"' ERR
rehearse() {
    reset_rehearsal_mailboxes || return "$?"
    "$@" || return "$?"
    assert_no_rehearsal_actors || return "$?"
    check_rehearsal_mailboxes bounded || return "$?"
    # A converted-native outer case reuses its own Light tree internally.
    # Archive only when that WHOLE outer command, with every check, passed.
    docker run --rm --user root --network none -e EMAILER_ISOLATED_REHEARSAL=1 -e EMAILER_REHEARSAL_CASE_COMPLETED=1 \
        -v "$project_root:/tools:ro" -v "$state_root:/rehearsals" "$app_image" \
        bash /tools/tools/ci/compact-rehearsal-evidence.sh
}
if docker network inspect "$network" >/dev/null 2>&1; then
    printf 'Refusing an existing edition network.\n' >&2
    exit 1
fi
database_id="$(docker compose ps --quiet database)"
test -n "$database_id"
docker network create --internal "$network" >/dev/null
plain_mailpit="${network}_mailpit"
auth_mailpit="${network}_auth_mailpit"
source "$project_root/tools/ci/rehearsal-mailboxes.sh"
cleanup() {
    local status="$?"
    if [ "$status" = 0 ] && assert_no_rehearsal_actors \
        && mailbox_owned_or_absent "$plain_mailpit" && mailbox_owned_or_absent "$auth_mailpit"; then
        remove_rehearsal_mailbox "$plain_mailpit" || return 1
        remove_rehearsal_mailbox "$auth_mailpit" || return 1
        docker network disconnect "$network" "$database_id" >/dev/null || return 1
        docker network rm "$network" >/dev/null || return 1
    else
        printf 'Preserving isolated failed-case mailboxes and network for diagnosis.\n' >&2
        if [ "$status" = 0 ]; then status=1; fi
    fi
    return "$status"
}
trap cleanup EXIT
docker network connect --alias database "$network" "$database_id"
bash "$project_root/tools/ci/check-rehearsal-mailbox-isolation.sh" "$network" "$database_id" "$app_image"
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
    php /tools/tools/release/build-package.php --artifact=/tools/build/artifact --output=/rehearsals/target-package --version=1.3.3 --source="$1" --minimum-source=1.0.6 --supported-sources=1.0.6,1.1.0,1.2.1,1.2.2,1.3.0,1.3.1,1.3.2 --minimum-schema=20261015000000 --schema=20261025000000 --rollback-schema-compatible=false --signing-key=/rehearsals/test-keys/signing.key --source-date-epoch="$2" >/dev/null
' bash "$source_commit" "$source_epoch"
for version in 105 106 110 121 122 130 131 132; do
    case "$version" in
        105) historical_commit=3927f17d73190b9e99861a8e3b34b572226665a1 ;;
        106) historical_commit=e522f8ae007c4232b170f4717247378c4b897863 ;;
        110) historical_commit=171d6aa658e3aca77c60fb9c7803303d8f4788cd ;;
        121) historical_commit=adb8b71f913519da580b389e3a5a11c243dabbb4 ;;
        122) historical_commit=25e703c14b3f9ac6b864e3ad1f7e35b3cc2b74d8 ;;
        130) historical_commit=7d031aed9cfacaececef83498871330e664e1a62 ;;
        131) historical_commit=485999a788cb75277f8cc67a2ec811ad249c0614 ;;
        132) historical_commit=2a8603f460aa2326faa79c570b5e52782c3a1bb6 ;;
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
        if [ "$1" = 105 ]; then release=1.0.5; schema=20261013000000; elif [ "$1" = 106 ]; then release=1.0.6; schema=20261015000000; elif [ "$1" = 110 ]; then release=1.1.0; schema=20261015000000; elif [ "$1" = 121 ]; then release=1.2.1; schema=20261015000000; elif [ "$1" = 122 ]; then release=1.2.2; schema=20261015000000; elif [ "$1" = 130 ]; then release=1.3.0; schema=20261015000000; elif [ "$1" = 131 ]; then release=1.3.1; schema=20261015000000; else release=1.3.2; schema=20261015000000; fi
        php /tools/tools/release/build-package.php --artifact="/rehearsals/source$1/build/artifact" --output="/rehearsals/native$1-package" --version="$release" --source="$2" --minimum-source=1.0.5 --supported-sources=1.0.5 --minimum-schema=20261013000000 --schema="$schema" --rollback-schema-compatible=false --signing-key=/rehearsals/test-keys/signing.key --source-date-epoch="$3" >/dev/null
    ' bash "$version" "$historical_commit" "$source_epoch"
    cp "$state_root/target-package/ripe.pub" "$state_root/native$version-package/ripe.pub"
done
export EMAILER_REHEARSAL_NETWORK="$network"
export EMAILER_REHEARSAL_APP_IMAGE="$app_image"
mkdir "$state_root/compaction-selfcheck"
docker run --rm --user root --network none \
    -v "$project_root:/tools:ro" -v "$state_root/compaction-selfcheck:/rehearsals" "$app_image" \
    bash /tools/tools/ci/check-rehearsal-compaction.sh
# Preserve complete historical failure/partial-DDL recovery coverage with the
# original reviewed1.0.6 code, tools and artifact. Test signatures are synthetic.
legacy="$state_root/source106/tools/upgrade"
legacy_target="$state_root/native106-package/emailer-next-1.0.6.zip"
legacy_key="$state_root/native106-package/ripe.pub"
rehearse bash "$legacy/run-native-update-rehearsal.sh" "$legacy_target" "$legacy_key" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" e522f8ae007c4232b170f4717247378c4b897863
rehearse env EMAILER_REHEARSAL_NATIVE_FAULT=1 bash "$legacy/run-native-update-rehearsal.sh" "$legacy_target" "$legacy_key" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" e522f8ae007c4232b170f4717247378c4b897863
for phase in journal preparing migrating; do
    rehearse env EMAILER_REHEARSAL_NATIVE_INTERRUPT="$phase" bash "$legacy/run-native-update-rehearsal.sh" "$legacy_target" "$legacy_key" "$state_root/native105-package/emailer-next-1.0.5.zip" "$state_root" e522f8ae007c4232b170f4717247378c4b897863
done
# Current bounded nullable-column update: real process interruption before checkpoint,
# plus post-activation failure and full source-schema checkpoint recovery.
target="$state_root/target-package/emailer-next-1.3.3.zip"
key="$state_root/target-package/ripe.pub"
rehearse bash tools/upgrade/run-fresh-ripe-rehearsal.sh "$target" "$key" "$state_root" "$source_commit"
rehearse bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$legacy_target" "$state_root" "$source_commit"
rehearse env EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$legacy_target" "$state_root" "$source_commit"
for phase in journal preparing; do
    rehearse env EMAILER_REHEARSAL_NATIVE_INTERRUPT="$phase" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$legacy_target" "$state_root" "$source_commit"
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root" "$source_commit" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root" "$source_commit" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.1.0 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native121-package/emailer-next-1.2.1.zip" "$state_root" "$source_commit" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.1 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native121-package/emailer-next-1.2.1.zip" "$state_root" "$source_commit" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.1 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native121-package/emailer-next-1.2.1.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.2 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native122-package/emailer-next-1.2.2.zip" "$state_root" "$source_commit" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.2 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native122-package/emailer-next-1.2.2.zip" "$state_root" "$source_commit" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.2.2 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native122-package/emailer-next-1.2.2.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.0 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native130-package/emailer-next-1.3.0.zip" "$state_root" "$source_commit" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.0 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native130-package/emailer-next-1.3.0.zip" "$state_root" "$source_commit" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.0 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native130-package/emailer-next-1.3.0.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing; do
    case "$mode" in
        success) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native131-package/emailer-next-1.3.1.zip" "$state_root" "$source_commit" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.1 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native131-package/emailer-next-1.3.1.zip" "$state_root" "$source_commit" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.1 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native131-package/emailer-next-1.3.1.zip" "$state_root" "$source_commit" ;;
    esac
done
for mode in success fault journal preparing migrating; do
    case "$mode" in
        success) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.2 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native132-package/emailer-next-1.3.2.zip" "$state_root" "$source_commit" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.2 EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native132-package/emailer-next-1.3.2.zip" "$state_root" "$source_commit" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_SOURCE=1.3.2 EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-native-update-rehearsal.sh "$target" "$key" "$state_root/native132-package/emailer-next-1.3.2.zip" "$state_root" "$source_commit" ;;
    esac
done
# Light's production pin is never substituted to accommodate a CI signer.
# Publish the fully accepted Light release before enabling the corresponding
# private Ripe release: public release sidecars are authenticated by current code.
for light_version in 1.1.0 1.2.0 1.2.1 1.2.2 1.2.3 1.2.4 1.2.5 1.2.6; do
    for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
        curl --fail --silent --show-error --location "https://github.com/Attory/emailer-light/releases/download/v$light_version/emailer-light-$light_version.zip$suffix" --output "$state_root/published-light/emailer-light-$light_version.zip$suffix"
    done
done
for mode in success fault journal preparing migrating; do
    case "$mode" in
        success) rehearse bash tools/upgrade/run-converted-native-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root/published-light/emailer-light-1.1.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit" "$state_root/source110" ;;
        fault) rehearse env EMAILER_REHEARSAL_NATIVE_FAULT=1 bash tools/upgrade/run-converted-native-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root/published-light/emailer-light-1.1.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit" "$state_root/source110" ;;
        *) rehearse env EMAILER_REHEARSAL_NATIVE_INTERRUPT="$mode" bash tools/upgrade/run-converted-native-rehearsal.sh "$target" "$key" "$state_root/native110-package/emailer-next-1.1.0.zip" "$state_root/published-light/emailer-light-1.1.0.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit" "$state_root/source110" ;;
    esac
done
for light_version in 1.2.6 1.2.5 1.2.4 1.0.0 1.1.0 1.2.0 1.2.1 1.2.2 1.2.3; do
    printf 'Checking rich and minimal published Light %s fixtures.\n' "$light_version"
    rehearse env EMAILER_REHEARSAL_LIGHT_VERSION="$light_version" bash tools/upgrade/run-light-rehearsal.sh "$target" "$key" "$state_root/published-light/emailer-light-$light_version.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit"
    rehearse env EMAILER_REHEARSAL_LIGHT_VERSION="$light_version" EMAILER_REHEARSAL_MINIMAL=1 bash tools/upgrade/run-light-rehearsal.sh "$target" "$key" "$state_root/published-light/emailer-light-$light_version.zip" "$state_root/published-light/emailer-light-release.pub" "$state_root" "$source_commit"
done
printf 'Historical and current signed-package edition rehearsals passed on this PHP/MariaDB matrix member.\n'
