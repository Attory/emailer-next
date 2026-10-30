#!/usr/bin/env bash
set -euo pipefail
test "${EMAILER_ISOLATED_REHEARSAL:-0}" = 1
test "$(id -u)" = 0
test -f /rehearsal/runtime.env
operation="$(php /tools/tools/upgrade/observe-native-interruption.php operation)"
env -i PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 setpriv --reuid=1000 --regid=1000 --clear-groups bash -euc '
 set -a; source /rehearsal/runtime.env; set +a
 exec php /rehearsal/source-releases/current/bin/console emailer:delivery:quiesce --no-interaction
'
php -r 'file_put_contents("/rehearsal/recovered-source-pause.json",json_encode(["format"=>"emailer-recovered-source-pause-v1","operation"=>$argv[1]],JSON_THROW_ON_ERROR));chmod("/rehearsal/recovered-source-pause.json",0600);' "$operation"
