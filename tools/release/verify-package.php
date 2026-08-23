<?php

declare(strict_types=1);

use App\Module\Release\Application\PackageInspector;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['archive:', 'public-key:', 'current-version:', 'expected-version:', 'expected-source:']);
foreach (['archive', 'public-key', 'current-version', 'expected-version', 'expected-source'] as $required) {
    if (!is_string($options[$required] ?? null) || '' === $options[$required]) {
        fwrite(STDERR, "Missing required verification option.\n");
        exit(2);
    }
}

try {
    $manifest = (new PackageInspector($options['public-key'], $options['current-version']))->inspect($options['archive']);
    if ($options['expected-version'] !== ($manifest['version'] ?? null) || $options['expected-source'] !== ($manifest['source_commit'] ?? null)) {
        throw new RuntimeException('Verified package identity differs from the expected release.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Release package verification failed.\n");
    exit(2);
}

fwrite(STDOUT, "Release package verified.\n");
