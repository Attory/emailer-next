<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Fault release is isolated-only.');
}
file_put_contents('/rehearsal/release-fault-lock', 'release');
chmod('/rehearsal/release-fault-lock', 0o600);
