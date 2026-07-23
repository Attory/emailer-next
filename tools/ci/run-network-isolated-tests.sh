#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"

network_name="${COMPOSE_PROJECT_NAME:-emailer_next_ci}_isolated"

if docker network inspect "$network_name" >/dev/null 2>&1; then
    printf 'Refusing to reuse an existing Docker network: %s\n' "$network_name" >&2
    exit 1
fi

docker network create --internal "$network_name" >/dev/null

cleanup() {
    docker network rm "$network_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

database_id="$(docker compose ps --quiet database)"
mailpit_id="$(docker compose ps --quiet mailpit)"
app_image="$(docker compose images --quiet app)"

if [ -z "$database_id" ] || [ -z "$mailpit_id" ] || [ -z "$app_image" ]; then
    printf 'The Compose services and application image must exist before isolation testing.\n' >&2
    exit 1
fi

docker network connect --alias database "$network_name" "$database_id"
docker network connect --alias mailpit "$network_name" "$mailpit_id"

docker run --rm \
    --network "$network_name" \
    "$app_image" \
    php -r '
        $socket = @stream_socket_client("tcp://192.0.2.1:25", $errorCode, $errorMessage, 1);
        if (false !== $socket) {
            fclose($socket);
            fwrite(STDERR, "Unexpected external egress from isolated test network.\n");
            exit(1);
        }
    '

docker run --rm \
    --network "$network_name" \
    --env APP_DEBUG=0 \
    --env APP_ENV=test \
    --env APP_SECRET=phase1-ci-only \
    --env "DATABASE_URL=${DATABASE_URL}" \
    --env DEFAULT_URI=https://emailer.example.test \
    --env LOCK_DSN=flock \
    --env MAILER_DSN=smtp://mailpit:1025 \
    --env MESSENGER_TRANSPORT_DSN=doctrine://default \
    "$app_image" \
    php bin/phpunit
