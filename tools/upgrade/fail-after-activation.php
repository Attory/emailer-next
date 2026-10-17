<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Activation fault is isolated-only.');
}
$target = '1' === getenv('EMAILER_REHEARSAL_NATIVE_TARGET') ? '/rehearsal/source-releases/1.2.1' : '/rehearsal/source-releases/ripe-1.2.1';
$deadline = microtime(true) + 45;
while (microtime(true) < $deadline) {
    clearstatcache(true);
    if ($target === realpath('/rehearsal/source-releases/current')) {
        if (!rename('/rehearsal/keys/master.key', '/rehearsal/activation-fault-retained-master.key')) {
            throw new RuntimeException('Synthetic activation fault could not be injected.');
        }
        file_put_contents('/rehearsal/activation-fault-observed', 'post-switch');
        chmod('/rehearsal/activation-fault-observed', 0o600);
        echo "Post-activation missing-key fault injected; source key retained securely.\n";
        exit;
    }
    usleep(1000);
}
throw new RuntimeException('The actual atomic activation pointer was not observed.');
