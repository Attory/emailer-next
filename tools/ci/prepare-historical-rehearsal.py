#!/usr/bin/env python3
"""Patch only historical test control; never change historical application bytes."""
import hashlib
from pathlib import Path
import sys

HISTORICAL_SHA256 = 'a013a2e3012658f77d75dd49fe818fbe61cd1afa053181daa6cc86b479358844'


def isolate(source):
    source = source.replace('iteration="$(date +%s)_${RANDOM}"', 'readonly iteration="$(date +%s)_${RANDOM}"')
    source = source.replace('for iteration in {1..100}', 'for poll in {1..100}')
    source = source.replace('case_root="$output_directory/light-$iteration"', 'case_root="$output_directory/light-$iteration"\nreadonly case_key="$(printf %s "$case_root" | sha256sum | cut -c1-16)"\nrehearsal_actor_tool="${EMAILER_REHEARSAL_ACTOR_TOOL:-$project_root/tools/ci/rehearsal-actors.py}"')
    source = source.replace('    local options=()', '    local options=()\n    if [ -n "${EMAILER_REHEARSAL_PHASE_OBSERVER:-}" ]; then options+=(-v "$EMAILER_REHEARSAL_PHASE_OBSERVER:/tools/tools/upgrade/observe-rehearsal-phase.php:ro"); fi')
    source = source.replace('docker run --rm "${options[@]}"', 'python3 "$rehearsal_actor_tool" run "$case_root" "$network" -- run --rm "${options[@]}"')
    for role in ('conflict', 'fault', 'upgrade'):
        source = source.replace('ripe106-' + role + '-$iteration', 'ripe106-' + role + '-${iteration}-${case_key}')
    source = source.replace('docker rm --force "$conflicting_operation"', 'python3 "$rehearsal_actor_tool" remove "$case_root" "$network" "$conflicting_operation"')
    source = source.replace('    docker rm --force "$fault_upgrade" "$fault_lock" >/dev/null 2>&1 || true', '    local failed=0\n    python3 "$rehearsal_actor_tool" remove "$case_root" "$network" "$fault_upgrade" || failed=1\n    python3 "$rehearsal_actor_tool" remove "$case_root" "$network" "$fault_lock" || failed=1\n    return "$failed"')
    source = source.replace('trap cleanup_faults EXIT', 'trap \'status=$?; cleanup_faults || status=1; exit "$status"\' EXIT')
    source = source.replace('docker kill "$fault_upgrade"', 'python3 "$rehearsal_actor_tool" kill "$case_root" "$network" "$fault_upgrade"')
    for name in ('conflicting_operation', 'fault_lock'):
        old = 'for poll in {1..100}; do\n    if ! docker container inspect "$' + name + '" >/dev/null 2>&1; then break; fi\n    sleep 0.1\ndone'
        source = source.replace(old, 'python3 "$rehearsal_actor_tool" wait "$case_root" "$network" "$' + name + '"')
    if 'for iteration ' in source or 'docker kill ' in source or 'docker rm --force' in source:
        raise ValueError('Unconverted historical actor control')
    return source


if __name__ == '__main__':
    source, output = map(Path, sys.argv[1:])
    contents = source.read_bytes()
    if hashlib.sha256(contents).hexdigest() != HISTORICAL_SHA256:
        raise ValueError('Historical rehearsal helper differs from the exact accepted1.1 source')
    value = isolate(contents.decode())
    value = value.replace('project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"', 'project_root="${EMAILER_HISTORICAL_REHEARSAL_ROOT:?}"')
    with output.open('x') as handle:
        handle.write(value)
    output.chmod(0o700)
