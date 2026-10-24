#!/usr/bin/env bash
# Sourced by the isolated CI driver. Never modifies the Compose application's
# mailbox. Only labelled edition sinks on this freshly created internal network.
# The sourcing driver must explicitly provide this isolated resource contract.
network="${network:?}"
database_id="${database_id:?}"
plain_mailpit="${plain_mailpit:?}"
auth_mailpit="${auth_mailpit:?}"
project_root="${project_root:?}"
app_image="${app_image:?}"
owned_rehearsal_mailbox_id() {
    local name="$1" id label actual_name networks
    [[ "$name" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || return 1
    # A successful exact-name inventory proves absence. An inspect/daemon
    # failure is never interpreted as a missing container.
    id="$(docker container ls --all --no-trunc --filter "name=^/${name}$" --format '{{.ID}}')" || return 1
    if [ -z "$id" ]; then return 0; fi
    actual_name="$(docker inspect --format '{{.Name}}' "$id")" || return 1
    label="$(docker inspect --format '{{index .Config.Labels "org.emailer.edition-ci"}}' "$id")" || return 1
    if [ "$actual_name" != "/$name" ] || [ "$label" != "$network" ]; then
        printf 'Refusing an unrelated container at an edition mailbox name.\n' >&2
        return 1
    fi
    networks="$(docker inspect --format '{{range $name, $value := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$id")" || return 1
    grep -Fx -- "$network" <<< "$networks" >/dev/null || return 1
    printf '%s\n' "$id"
}
mailbox_owned_or_absent() {
    owned_rehearsal_mailbox_id "$1" >/dev/null
}
remove_rehearsal_mailbox() {
    local id
    id="$(owned_rehearsal_mailbox_id "$1")" || return 1
    if [ -n "$id" ]; then docker rm --force "$id" >/dev/null; fi
}
assert_no_rehearsal_actors() {
    local database_name actor actors
    test "$(docker network inspect --format '{{.Internal}}' "$network")" = true || return 1
    database_name="$(docker inspect --format '{{.Name}}' "$database_id")" || return 1
    database_name="${database_name#/}"
    actors="$(docker network inspect --format '{{range .Containers}}{{.Name}}{{"\n"}}{{end}}' "$network")" || return 1
    while IFS= read -r actor; do
        case "$actor" in
            "$database_name"|"$plain_mailpit"|"$auth_mailpit"|'') ;;
            *) printf 'A test actor remains attached; refusing mailbox retirement.\n' >&2; return 1 ;;
        esac
    done <<< "$actors"
}
check_rehearsal_mailboxes() {
    docker run --rm --user root --network "$network" -e EMAILER_ISOLATED_REHEARSAL=1 \
        -v "$project_root:/tools:ro" "$app_image" php /tools/tools/ci/check-rehearsal-mailboxes.php "$1"
}
reset_rehearsal_mailboxes() {
    local name alias attempt ready
    local options=()
    assert_no_rehearsal_actors || return 1
    # Validate BOTH destinations before changing either; an unexpected name or
    # actor is a failure, never a reason to clear message evidence.
    mailbox_owned_or_absent "$plain_mailpit" || return 1
    mailbox_owned_or_absent "$auth_mailpit" || return 1
    remove_rehearsal_mailbox "$plain_mailpit" || return 1
    remove_rehearsal_mailbox "$auth_mailpit" || return 1
    for alias in mailpit auth-mailpit; do
        name="$plain_mailpit"
        options=()
        if [ "$alias" = auth-mailpit ]; then
            name="$auth_mailpit"
            options=(-e MP_SMTP_AUTH_ACCEPT_ANY=true -e MP_SMTP_AUTH_ALLOW_INSECURE=true)
        fi
        docker run --detach --name "$name" --network "$network" --network-alias "$alias" \
            --label "org.emailer.edition-ci=$network" -e MP_DISABLE_VERSION_CHECK=true \
            -e MP_MAX_MESSAGES=100 -e MP_DATABASE=/tmp/mailpit.db "${options[@]}" axllent/mailpit:v1.30.5 >/dev/null || return 1
        ready=0
        attempt=0
        while [ "$attempt" -lt 50 ]; do
            attempt=$((attempt + 1))
            if docker exec "$name" /mailpit readyz >/dev/null 2>&1; then ready=1; break; fi
            sleep 0.1
        done
        test "$ready" = 1 || return 1
    done
    check_rehearsal_mailboxes empty
}
