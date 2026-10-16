#!/usr/bin/env python3
"""Prove the single published synthetic-URL exception has narrow boundaries."""
from pathlib import Path
import subprocess

scanner = Path(__file__).with_name('scan-private-data.sh').read_text()
line = next(line for line in scanner.splitlines() if line.startswith("email_pattern='"))
pattern = line[len("email_pattern='"):-1]
fixture = 'synthetic-secret' + '@' + 'news.example.test'
cases = [
    ('https://user:' + fixture, False),
    (fixture, True),
    ('https://other:' + fixture, True),
    ('https://user:changed-' + fixture, True),
    ('https://user:' + fixture + '.invalid', True),
    ('https://user:' + fixture.replace('example.test', 'private.invalid'), True),
    ('person' + '@' + 'private.invalid', True),
    ('person' + '@' + 'example.test', False),
]
for number, (text, expected) in enumerate(cases, 1):
    result = subprocess.run(['rg', '--quiet', '--text', '--ignore-case', '--pcre2', '--', pattern], input=text, text=True)
    if result.returncode not in (0, 1) or (result.returncode == 0) != expected:
        raise SystemExit('Private-data pattern boundary case ' + str(number) + ' failed.')
print('Eight private-data pattern boundary cases passed; full file/history scanning remains enabled.')
