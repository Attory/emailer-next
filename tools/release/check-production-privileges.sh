#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [ "$(id -u)" -ne 0 ]; then
    printf 'Production privilege check must run as root.\n' >&2
    exit 2
fi

temporary_root="$(mktemp -d)"
cleanup() {
    chmod -R u+w "$temporary_root"
    find "$temporary_root" -mindepth 1 -depth -delete
    rmdir "$temporary_root"
}
trap cleanup EXIT
chmod 0755 "$temporary_root"

service_uid="$(id -u emailer)"
service_gid="$(id -g emailer)"
mkdir -m 0755 "$temporary_root/project" "$temporary_root/project/public" "$temporary_root/output" "$temporary_root/releases"
mkdir -m 0700 "$temporary_root/update-runtime"
install -d -o "$service_uid" -g "$service_gid" -m 0700 "$temporary_root/keys"
cp -a "$project_root/build/artifact" "$temporary_root/runner"
chmod -R u+w "$temporary_root/runner"

setpriv --reuid="$service_uid" --regid="$service_gid" --clear-groups \
    php "$project_root/tools/security/check-master-key-access.php" create \
    "$temporary_root/keys/master.key" "$temporary_root/project/public" "$temporary_root/project"
php "$project_root/tools/security/check-master-key-access.php" load \
    "$temporary_root/keys/master.key" "$temporary_root/project/public" "$temporary_root/project"
if php "$project_root/tools/security/check-master-key-access.php" replace \
    "$temporary_root/keys/master.key" "$temporary_root/project/public" "$temporary_root/project" 2>/dev/null; then
    printf 'Root updater identity could mutate the service-owned master key.\n' >&2
    exit 1
fi

php "$project_root/tools/release/generate-test-keypair.php" "$temporary_root/signing.key" "$temporary_root/release.pub"
php "$project_root/tools/release/build-package.php" \
    --artifact="$project_root/build/artifact" \
    --output="$temporary_root/output" \
    --version=1.0.2 \
    --source="$(git -C "$project_root" rev-parse HEAD)" \
    --minimum-source=1.0.0 \
    --supported-sources=1.0.0 \
    --minimum-schema=20260828000000 \
    --schema=20261008000000 \
    --rollback-schema-compatible=false \
    --signing-key="$temporary_root/signing.key" \
    --source-date-epoch="$(git -C "$project_root" show -s --format=%ct HEAD)" >/dev/null

APP_ENV=prod \
APP_DEBUG=0 \
APP_SECRET=phase13-production-privilege-check \
DATABASE_URL='mysql://emailer:local-only@database:3306/emailer_test?serverVersion=10.11.19-MariaDB&charset=utf8mb4' \
DEFAULT_URI=https://emailer.example.test \
EMAILER_PRIVATE_DIR="$temporary_root/private" \
EMAILER_RUNTIME_DIR="$temporary_root/update-runtime" \
EMAILER_SECRET_KEY_FILE="$temporary_root/keys/master.key" \
EMAILER_RELEASE_PUBLIC_KEY_FILE="$temporary_root/release.pub" \
EMAILER_RELEASES_DIR="$temporary_root/releases" \
LOCK_DSN=flock \
php "$temporary_root/runner/bin/console" emailer:release:install "$temporary_root/output/emailer-next-1.0.2.zip" --no-interaction >/dev/null

test "$(stat -c '%u:%a' "$temporary_root/releases/1.0.2")" = '0:555'
test "$(stat -c '%u:%a' "$temporary_root/releases/1.0.2/bin/console")" = '0:444'
test -d "$temporary_root/update-runtime/cache/prod"
test ! -e "$temporary_root/private/cache"
if setpriv --reuid="$service_uid" --regid="$service_gid" --clear-groups chmod u+w "$temporary_root/releases/1.0.2/bin/console" 2>/dev/null; then
    printf 'Ordinary application identity could make the published release writable.\n' >&2
    exit 1
fi

printf 'Root-owned release publication and service-key read boundary verified.\n'
