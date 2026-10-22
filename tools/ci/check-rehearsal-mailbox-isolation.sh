#!/usr/bin/env bash
set -euo pipefail
# Integration regression: only the driver's fresh internal edition network.
test "$#" = 3
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
network="$1"
database_id="$2"
app_image="$3"
plain_mailpit="${network}_mailpit"
auth_mailpit="${network}_auth_mailpit"
source "$project_root/tools/ci/rehearsal-mailboxes.sh"
actor_id=''
foreign_id=''
cleanup_test_actors() {
    if [ -n "$actor_id" ]; then docker rm --force "$actor_id" >/dev/null; fi
    if [ -n "$foreign_id" ]; then docker rm --force "$foreign_id" >/dev/null; fi
}
trap cleanup_test_actors EXIT
reset_rehearsal_mailboxes
plain_id="$(docker inspect --format '{{.Id}}' "$plain_mailpit")"
auth_id="$(docker inspect --format '{{.Id}}' "$auth_mailpit")"
# Simulated daemon/inventory or inspect errors must never become absence/removal.
for failure in inventory inspect; do
    (
        docker() {
            if { [ "$failure" = inventory ] && [ "$1" = container ]; } \
                || { [ "$failure" = inspect ] && [ "$1" = inspect ]; }; then return 77; fi
            command docker "$@"
        }
        if remove_rehearsal_mailbox "$plain_mailpit"; then exit 1; fi
    )
    test "$(docker inspect --format '{{.Id}}' "$plain_mailpit")" = "$plain_id"
done
# An attached test actor prevents retirement of either sink/evidence.
actor_id="$(docker run --detach --network "$network" "$app_image" sleep 120)"
if reset_rehearsal_mailboxes; then exit 1; fi
test "$(docker inspect --format '{{.Id}}' "$plain_mailpit")" = "$plain_id"
test "$(docker inspect --format '{{.Id}}' "$auth_mailpit")" = "$auth_id"
docker rm --force "$actor_id" >/dev/null
actor_id=''
# A conflicting, unlabelled destination is never removed, even inside an if.
owned_plain="$plain_mailpit"
plain_mailpit="${network}_foreign"
foreign_id="$(docker run --detach --name "$plain_mailpit" --network none "$app_image" sleep 120)"
if remove_rehearsal_mailbox "$plain_mailpit"; then exit 1; fi
if reset_rehearsal_mailboxes; then exit 1; fi
test "$(docker inspect --format '{{.Id}}' "$plain_mailpit")" = "$foreign_id"
test "$(docker inspect --format '{{.Id}}' "$auth_mailpit")" = "$auth_id"
plain_mailpit="$owned_plain"
docker rm --force "$foreign_id" >/dev/null
foreign_id=''
# Deliberate, identifiable synthetic messages stay on this no-egress sink.
# At the headroom boundary the count check MUST fail; no assertion is relaxed.
docker run --rm --network "$network" "$app_image" php -r '
function reply($socket): void {
    do { $line = fgets($socket); if (false === $line) { throw new RuntimeException("Synthetic sink disconnected."); } } while (isset($line[3]) && "-" === $line[3]);
    if (!in_array(substr($line, 0, 3), ["220", "250", "354", "221"], true)) { throw new RuntimeException("Synthetic sink refused the regression message."); }
}
$socket = fsockopen("mailpit", 1025, $code, $error, 5);
if (false === $socket) { throw new RuntimeException("Synthetic sink unavailable."); }
stream_set_timeout($socket, 5);
reply($socket);
fwrite($socket, "EHLO isolation-regression.invalid\r\n"); reply($socket);
for ($i = 0; $i < 80; ++$i) {
    foreach (["MAIL FROM:<isolation-regression@example.invalid>", "RCPT TO:<sink@example.invalid>", "DATA"] as $command) { fwrite($socket, $command."\r\n"); reply($socket); }
    fwrite($socket, "From: isolation-regression@example.invalid\r\nTo: sink@example.invalid\r\nSubject: Explicit synthetic CI retention regression\r\n\r\nSynthetic isolation test only.\r\n.\r\n"); reply($socket);
}
fwrite($socket, "QUIT\r\n"); reply($socket); fclose($socket);
'
if check_rehearsal_mailboxes bounded; then exit 1; fi
# With no actors and both ownership checks passing, a new outer case starts at0.
reset_rehearsal_mailboxes
printf 'Mailbox actor, ownership, retention-headroom and fresh-case isolation checks passed.\n'
