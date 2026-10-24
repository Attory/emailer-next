#!/usr/bin/env bash
set -euo pipefail
test "$EUID" = 0
test -d /rehearsals
test -z "$(find /rehearsals -mindepth 1 -maxdepth 1 -print -quit)"
export EMAILER_ISOLATED_REHEARSAL=1 EMAILER_REHEARSAL_CASE_COMPLETED=1
mkdir -m0700 /rehearsals/light-1_1 /rehearsals/do-not-compact
printf 'Synthetic protected fixture only\n' > /rehearsals/light-1_1/private-material
chmod 0600 /rehearsals/light-1_1/private-material
printf 'Unrelated test resource\n' > /rehearsals/do-not-compact/sentinel
expected="$(sha256sum /rehearsals/light-1_1/private-material | cut -d' ' -f1)"
bash /tools/tools/ci/compact-rehearsal-evidence.sh
test ! -e /rehearsals/light-1_1
test -f /rehearsals/do-not-compact/sentinel
sha256sum --check --status /rehearsals/passed-evidence/light-1_1.tar.gz.sha256
verify="$(mktemp -d)"
trap 'rm -rf -- "$verify"' EXIT
tar --extract --gzip --file=/rehearsals/passed-evidence/light-1_1.tar.gz --directory="$verify"
test "$(sha256sum "$verify/light-1_1/private-material" | cut -d' ' -f1)" = "$expected"
test "$(stat -c %a "$verify/light-1_1/private-material")" = 600
if EMAILER_REHEARSAL_CASE_COMPLETED=0 bash /tools/tools/ci/compact-rehearsal-evidence.sh; then exit 1; fi
if EMAILER_ISOLATED_REHEARSAL=0 bash /tools/tools/ci/compact-rehearsal-evidence.sh; then exit 1; fi
ln -s /rehearsals/do-not-compact /rehearsals/light-2_2
if bash /tools/tools/ci/compact-rehearsal-evidence.sh; then exit 1; fi
test -f /rehearsals/do-not-compact/sentinel
test -L /rehearsals/light-2_2
rm -- /rehearsals/light-2_2
mkdir -m0700 /rehearsals/light-3_3
printf 'Retain the unarchived fixture\n' > /rehearsals/light-3_3/evidence
chmod 0777 /rehearsals/passed-evidence
if bash /tools/tools/ci/compact-rehearsal-evidence.sh; then exit 1; fi
chmod 0700 /rehearsals/passed-evidence
ln -s ../do-not-compact/must-stay-absent /rehearsals/passed-evidence/light-3_3.tar.gz
if bash /tools/tools/ci/compact-rehearsal-evidence.sh; then exit 1; fi
test ! -e /rehearsals/do-not-compact/must-stay-absent
test -f /rehearsals/light-3_3/evidence
rm -- /rehearsals/passed-evidence/light-3_3.tar.gz
sentinel="$(sha256sum /rehearsals/do-not-compact/sentinel | cut -d' ' -f1)"
ln -s ../do-not-compact/sentinel /rehearsals/passed-evidence/light-3_3.tar.gz.sha256
if bash /tools/tools/ci/compact-rehearsal-evidence.sh; then exit 1; fi
test ! -e /rehearsals/passed-evidence/light-3_3.tar.gz
test "$(sha256sum /rehearsals/do-not-compact/sentinel | cut -d' ' -f1)" = "$sentinel"
test -f /rehearsals/light-3_3/evidence
printf 'Evidence preservation, isolated/completed guards and unsafe destination refusals passed.\n'
