#!/usr/bin/env bash
set -euo pipefail

# Run only against an already-created internal test network. No SSH or production inputs.
if [ "$#" -ne 6 ]; then
    printf 'Usage: %s TARGET_ZIP PINNED_RIPE_PUBLIC_KEY LIGHT_ZIP LIGHT_PUBLIC_KEY OUTPUT_DIR EXPECTED_COMMIT\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
archive="$(realpath "$1")"
public_key="$(realpath "$2")"
light_archive="$(realpath "$3")"
light_public_key="$(realpath "$4")"
output_directory="$(realpath "$5")"
expected_commit="$6"
app_image="${EMAILER_REHEARSAL_APP_IMAGE:-emailer-next-app:latest}"
network="${EMAILER_REHEARSAL_NETWORK:?An internal disposable network is required}"
test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
light_version="${EMAILER_REHEARSAL_LIGHT_VERSION:-1.0.0}"
case "$light_version" in 1.0.0|1.1.0|1.2.0|1.2.1|1.2.2|1.2.3|1.2.4|1.2.5|1.2.6|1.2.7) ;; *) exit 2 ;; esac
if [ "$light_version" = 1.0.0 ]; then
    test "$(sha256sum "$light_archive" | cut -d' ' -f1)" = cfd9afb133c3bbfe7da6053aa2d121d325200c46f4db51e784699356d28d0e77
fi
test -d "$project_root/vendor"
test -d "$output_directory"

readonly iteration="$(date +%s)_${RANDOM}"
case_root="$output_directory/light-$iteration"
readonly case_key="$(printf %s "$case_root" | sha256sum | cut -c1-16)"
rehearsal_actor_tool="${EMAILER_REHEARSAL_ACTOR_TOOL:-$project_root/tools/ci/rehearsal-actors.py}"
mkdir -m 0755 "$case_root"
phase() { printf '%s\n' "$1" > "$case_root/rehearsal-phase"; }
phase source-install
touch "$case_root/source-install.log" "$case_root/source-fixture.log" "$case_root/inspection.log" "$case_root/transition.log" "$case_root/preservation.log" "$case_root/recovery.log" "$case_root/rerun.log" "$case_root/fault.log" "$case_root/acceptance.log" "$case_root/old-links.log" "$case_root/owner-login.log" "$case_root/rejections.log"
# The host path contains no credentials; Docker owns all protected state below it.
run() {
    local options=()
    if [ -n "${rehearsal_detach:-}" ]; then options+=(--detach); fi
    if [ -n "${rehearsal_container:-}" ]; then options+=(--name "$rehearsal_container"); fi
    if [ -d "$case_root/legacy-export" ]; then options+=(-v "$case_root/legacy-export:/old-export:ro"); fi
    python3 "$rehearsal_actor_tool" run "$case_root" "$network" -- run --rm "${options[@]}" --user "${rehearsal_user:-root}" --network "$network" \
        -v "$project_root:/tools:ro" \
        -v "$case_root:/original-rehearsal" \
        -v "${rehearsal_case_root_override:-$case_root}:/rehearsal" \
        -v "$archive:/package/emailer-next-1.3.4.zip:ro" \
        -v "$archive.manifest.json:/package/emailer-next-1.3.4.zip.manifest.json:ro" \
        -v "$archive.sha256:/package/emailer-next-1.3.4.zip.sha256:ro" \
        -v "$archive.spdx.json:/package/emailer-next-1.3.4.zip.spdx.json:ro" \
        -v "$archive.sig:/package/emailer-next-1.3.4.zip.sig:ro" \
        -v "$public_key:/package/ripe.pub:ro" \
        -v "$(dirname "$light_archive"):/published-light:ro" \
        -v "$light_public_key:/published-light/emailer-light-release.pub:ro" \
        -e EMAILER_ISOLATED_REHEARSAL=1 \
        -e EMAILER_REHEARSAL_LIGHT_VERSION="$light_version" \
        -e "MARIADB_SERVER_VERSION=${MARIADB_SERVER_VERSION:-10.11.19-MariaDB}" \
        "$app_image" "$@"
}
run php /tools/tools/release/verify-package.php --archive=/package/emailer-next-1.3.4.zip --public-key=/package/ripe.pub --current-version=1.3.4 --expected-version=1.3.4 --expected-source="$expected_commit" > "$case_root/package-verification.txt"
run php /tools/tools/upgrade/prepare-light-rehearsal.php "ripe106_$iteration" /published-light/emailer-light-"$light_version".zip /package/ripe.pub
rehearsal_user=1000 run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}/bin/console emailer:setup:install --complete < /rehearsal/install-input' > "$case_root/source-install.log" 2>&1
phase source-fixture
if [ "${EMAILER_REHEARSAL_MINIMAL:-0}" != 1 ]; then
    run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /tools/tools/upgrade/seed-published-light.php' > "$case_root/source-fixture.log" 2>&1
fi
run chmod -R a-w /rehearsal/source-releases/"$light_version"
rehearsal_user=1000 run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}/bin/console emailer:backup:create; printf "%s\n%s\n" "Synthetic Light recovery passphrase only" "Synthetic Light recovery passphrase only" | php /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}/bin/console emailer:secrets:export-recovery-key' >> "$case_root/source-fixture.log" 2>&1
run bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/source-http-runtime; set +a; php /tools/tools/upgrade/issue-source-session.php' >> "$case_root/source-fixture.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-light-rehearsal.php before' > "$case_root/preservation.log"
run php -r '$z=new ZipArchive();$z->open("/package/emailer-next-1.3.4.zip");mkdir("/rehearsal/target");$z->extractTo("/rehearsal/target");'
phase transition
for variant in unsupported_version schema_drift mismatched_roots; do
    run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/reject-source-fixtures.php "$1"' bash "$variant" >> "$case_root/rejections.log"
    if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=inspect --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/rejections.log" 2>&1; then
        printf 'Unsupported source fixture was accepted: %s\n' "$variant" >&2
        exit 1
    fi
    run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/reject-source-fixtures.php restore' >> "$case_root/rejections.log"
done
run php /tools/tools/upgrade/tamper-rehearsal-package.php >> "$case_root/rejections.log"
if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=inspect --target-package=/rehearsal/tampered/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/rejections.log" 2>&1; then
    printf 'A tampered package was accepted.\n' >&2
    exit 1
fi
conflicting_operation="ripe106-conflict-${iteration}-${case_key}"
rehearsal_detach=1 rehearsal_container="$conflicting_operation" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/hold-edition-operation.php' > /dev/null
run bash -euc 'for poll in {1..100}; do test ! -f /rehearsal/conflicting-operation-ready || exit 0; sleep 0.1; done; exit 1'
if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/rejections.log" 2>&1; then
    python3 "$rehearsal_actor_tool" remove "$case_root" "$network" "$conflicting_operation" >/dev/null
    printf 'A conflicting database operation was accepted.\n' >&2
    exit 1
fi
run php -r 'file_put_contents("/rehearsal/release-conflicting-operation", "release");chmod("/rehearsal/release-conflicting-operation",0600);'
python3 "$rehearsal_actor_tool" wait "$case_root" "$network" "$conflicting_operation"
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=inspect --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' > "$case_root/inspection.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/active-claim-refusal.php prepare'
if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/rejections.log" 2>&1; then
    printf 'An active-claim marker was accepted.\n' >&2
    exit 1
fi
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/active-claim-refusal.php verify; php /tools/tools/upgrade/observe-light-rehearsal.php restored' >> "$case_root/rejections.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/conflict-rehearsal-database.php prepare'
if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/rejections.log" 2>&1; then
    printf 'An unowned nonempty rehearsal database was accepted.\n' >&2
    exit 1
fi
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/conflict-rehearsal-database.php cleanup; php /tools/tools/upgrade/observe-light-rehearsal.php restored' >> "$case_root/rejections.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' > "$case_root/transition.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-light-rehearsal.php after' >> "$case_root/preservation.log"
# Recover after atomic activation, preserving the same signed operation package.
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=recover --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery; php /tools/tools/upgrade/observe-light-rehearsal.php restored' > "$case_root/recovery.log" 2>&1

fault_lock="ripe106-fault-${iteration}-${case_key}"
fault_upgrade="ripe106-upgrade-${iteration}-${case_key}"
cleanup_faults() {
    local failed=0
    python3 "$rehearsal_actor_tool" remove "$case_root" "$network" "$fault_upgrade" || failed=1
    python3 "$rehearsal_actor_tool" remove "$case_root" "$network" "$fault_lock" || failed=1
    return "$failed"
}
trap 'status=$?; cleanup_faults || status=1; exit "$status"' EXIT
# A READ table lock allows complete source inspection/backup but blocks a real
# ALTER after earlier DDL has committed. No migration SQL is injected by tests.
rehearsal_detach=1 rehearsal_container="$fault_lock" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/hold-rehearsal-table.php encrypted_secret' > /dev/null
run bash -euc 'for poll in {1..100}; do test ! -f /rehearsal/fault-lock-ready || exit 0; sleep 0.1; done; exit 1'
rehearsal_detach=1 rehearsal_container="$fault_upgrade" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' > /dev/null
run php /tools/tools/upgrade/observe-rehearsal-phase.php mutating > "$case_root/fault.log"
python3 "$rehearsal_actor_tool" kill "$case_root" "$network" "$fault_upgrade" >/dev/null
run php /tools/tools/upgrade/release-fault-lock.php
# Wait for the fault lock to disappear before the real recovery invocation.
python3 "$rehearsal_actor_tool" wait "$case_root" "$network" "$fault_lock"
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery; php /tools/tools/upgrade/observe-light-rehearsal.php after' > "$case_root/rerun.log" 2>&1
cleanup_faults
# A wrong passphrase for the retained target recovery package fails before
# the first schema conversion statement and must recover the exact source.
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=recover --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/recovery.log" 2>&1
if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Wrong synthetic recovery passphrase only" "Wrong synthetic recovery passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/fault.log" 2>&1; then
    printf 'Pre-schema recovery-package fault unexpectedly passed.\n' >&2
    exit 1
fi
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-light-rehearsal.php restored; php /tools/tools/upgrade/lock-recovered-application.php light; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=recover --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery; php /tools/tools/upgrade/observe-light-rehearsal.php restored' >> "$case_root/recovery.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/rerun.log" 2>&1
# Interrupt recovery while DROP TABLE has partially committed. The durable
# recovering decision must survive and restore the source again on the next run.
run php -r 'unlink("/rehearsal/fault-lock-ready");unlink("/rehearsal/release-fault-lock");'
rehearsal_detach=1 rehearsal_container="$fault_lock" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/hold-rehearsal-table.php encrypted_secret' > /dev/null
run bash -euc 'for poll in {1..100}; do test ! -f /rehearsal/fault-lock-ready || exit 0; sleep 0.1; done; exit 1'
rehearsal_detach=1 rehearsal_container="$fault_upgrade" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=recover --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' > /dev/null
run php /tools/tools/upgrade/observe-rehearsal-phase.php recovering >> "$case_root/fault.log"
python3 "$rehearsal_actor_tool" kill "$case_root" "$network" "$fault_upgrade" >/dev/null
run php /tools/tools/upgrade/release-fault-lock.php
python3 "$rehearsal_actor_tool" wait "$case_root" "$network" "$fault_lock"
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=recover --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery; php /tools/tools/upgrade/observe-light-rehearsal.php restored' >> "$case_root/recovery.log" 2>&1
# Fail Doctor after the atomic pointer switch; the exact source key is retained
# by the injector, while the installer must restore its verified checkpoint.
rehearsal_detach=1 rehearsal_container="$fault_lock" run php /tools/tools/upgrade/fail-after-activation.php > /dev/null
if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/fault.log" 2>&1; then
    printf 'Post-activation fault unexpectedly passed.\n' >&2
    exit 1
fi
run test -f /rehearsal/activation-fault-observed
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-light-rehearsal.php restored' >> "$case_root/recovery.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery; php /tools/tools/upgrade/observe-light-rehearsal.php after' >> "$case_root/rerun.log" 2>&1
# Explicit operator acceptance unlocks the scoped application account but keeps
# the durable hold. HTTP checks are separate from conversion/recovery evidence.
run php -r 'unlink("/rehearsal/fault-lock-ready");unlink("/rehearsal/release-fault-lock");'
rehearsal_detach=1 rehearsal_container="$fault_lock" run bash -euc 'set -a; source /rehearsal/updater.env; export DATABASE_URL="$EMAILER_TRANSITION_RECOVERY_DATABASE_URL"; set +a; php /tools/tools/upgrade/hold-rehearsal-table.php encrypted_secret' > /dev/null
run bash -euc 'for poll in {1..100}; do test ! -f /rehearsal/fault-lock-ready || exit 0; sleep 0.1; done; exit 1'
rehearsal_detach=1 rehearsal_container="$fault_upgrade" run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=accept --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' > /dev/null
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/observe-scratch-cleanup.php' >> "$case_root/fault.log"
python3 "$rehearsal_actor_tool" kill "$case_root" "$network" "$fault_upgrade" >/dev/null
run php /tools/tools/upgrade/release-fault-lock.php
python3 "$rehearsal_actor_tool" wait "$case_root" "$network" "$fault_lock"
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=accept --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' > "$case_root/acceptance.log" 2>&1
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/verify-scratch-cleanup.php' >> "$case_root/acceptance.log" 2>&1
if [ "${EMAILER_REHEARSAL_MINIMAL:-0}" != 1 ]; then
    run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/verify-retained-queue.php' >> "$case_root/preservation.log" 2>&1
    run bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/http-runtime; set +a; php /tools/tools/upgrade/verify-old-links.php' > "$case_root/old-links.log" 2>&1
fi
run bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/owner-runtime; set +a; php /tools/tools/upgrade/verify-owner-login.php' > "$case_root/owner-login.log" 2>&1
for action in execute recover; do
    if run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action="$1" --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' bash "$action" >> "$case_root/rejections.log" 2>&1; then
        printf 'Completed transition allowed repeat conversion or downgrade.\n' >&2
        exit 1
    fi
done
# Restore a retained pre-upgrade Light backup only into a second actual Light
# installation. No active Ripe database, private tree or pointer is downgraded.
run bash -euc 'set -a; source /rehearsal/updater.env; set +a; checkpoint=$(php /tools/tools/upgrade/recovery-checkpoint-path.php); php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=export-light-recovery --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory="$checkpoint" --light-recovery-export-directory=/rehearsal/legacy-export; mkdir -m 0755 /rehearsal/legacy-source'
legacy_run() { rehearsal_case_root_override="$case_root/legacy-source" run "$@"; }
legacy_database="ripe106_$(date +%s)_${RANDOM}_legacy"
phase legacy-clone-setup
legacy_run php /tools/tools/upgrade/prepare-light-rehearsal.php "$legacy_database" /published-light/emailer-light-"$light_version".zip /package/ripe.pub
rehearsal_user=1000 legacy_run bash -euc 'set -a; source /rehearsal/runtime.env; set +a; php /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}/bin/console emailer:setup:install --complete < /rehearsal/install-input' >> "$case_root/source-install.log" 2>&1
phase legacy-clone-restore
legacy_run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/prepare-old-backup-restore.php; export EMAILER_RUNTIME_DIR=/rehearsal/legacy-restore-runtime; export EMAILER_RECOVERY_KEY_FILE=/rehearsal/keys/light-recovery.key; export EMAILER_RELEASE_PUBLIC_KEY_FILE=/published-light/emailer-light-release.pub; old_backup=$(cat /rehearsal/old-backup-path); printf "yes\n" | php /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}/bin/console emailer:backup:restore "$old_backup" --drill-receipt; php /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}/bin/console emailer:doctor:run --json --maintenance-verification || test "$?" = 1' >> "$case_root/recovery.log" 2>&1
# The unchanged Light restore itself authenticates every restored row/asset and
# emits signed drill evidence. Independently check archive/key, durable hold and
# zero new attempts/messages, then inspect the supported upgrade from that clone.
phase legacy-clone-inspect
legacy_run bash -euc 'set -a; source /rehearsal/updater.env; set +a; php /tools/tools/upgrade/verify-old-backup-restore.php; php /tools/tools/upgrade/unlock-restored-light-clone.php; chmod -R a-w /rehearsal/source-releases/${EMAILER_REHEARSAL_LIGHT_VERSION}; php /tools/tools/upgrade/observe-light-rehearsal.php before; php /tools/tools/upgrade/extract-rehearsal-target.php; php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=inspect --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery' >> "$case_root/recovery.log" 2>&1
phase legacy-clone-execute
legacy_run bash -euc 'set -a; source /rehearsal/updater.env; set +a; printf "%s\n%s\n" "Synthetic recovery rehearsal passphrase only" "Synthetic recovery rehearsal passphrase only" | php /rehearsal/target/bin/console emailer:setup:install --upgrade-light --edition-action=execute --target-package=/package/emailer-next-1.3.4.zip --source-package=/published-light/emailer-light-${EMAILER_REHEARSAL_LIGHT_VERSION}.zip --recovery-directory=/rehearsal/recovery; php /tools/tools/upgrade/observe-light-rehearsal.php after' >> "$case_root/recovery.log" 2>&1
if [ "${EMAILER_REHEARSAL_MINIMAL:-0}" != 1 ]; then
    # All conversion/recovery zero-send comparisons are complete. This separate
    # operation explicitly releases the accepted hold and authenticates the
    # preserved SMTP credentials to a dedicated synthetic AUTH sink.
    phase postaccept-smtp
    run bash -euc 'set -a; source /rehearsal/runtime.env; export EMAILER_RUNTIME_DIR=/rehearsal/post-acceptance-runtime; set +a; php /rehearsal/source-releases/ripe-1.3.4/bin/console emailer:delivery:resume; php /tools/tools/upgrade/verify-preserved-smtp.php' >> "$case_root/acceptance.log" 2>&1
    # Native Ripe backups made after conversion retain campaign-owned data.
    # Restore using the real command and authenticated every-row/asset drill.
    phase native-backup
    run bash -euc 'set -a; source /rehearsal/updater.env; export EMAILER_RUNTIME_DIR=/rehearsal/native-backup-drill-runtime; set +a; php /rehearsal/source-releases/ripe-1.3.4/bin/console emailer:delivery:quiesce; php /tools/tools/upgrade/native-backup-restore-evidence.php before; backup=$(cat /rehearsal/native-backup-path); printf "yes\n" | php /rehearsal/source-releases/ripe-1.3.4/bin/console emailer:backup:restore "$backup" --drill-receipt; php /tools/tools/upgrade/native-backup-restore-evidence.php after; php /tools/tools/upgrade/verify-old-links.php' >> "$case_root/recovery.log" 2>&1
fi
phase complete
printf 'Packaged synthetic Light transition completed: %s\n' "$case_root"
