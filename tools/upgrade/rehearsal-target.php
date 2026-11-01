<?php

declare(strict_types=1);

// Current rehearsal targets follow canonical configuration, never historical source labels.
$services = file_get_contents(dirname(__DIR__, 2).'/config/services.yaml');
$matches = [];
if (false === $services || 1 !== preg_match_all('/^\s*emailer\.app_version\s*:/m', $services) || 1 !== preg_match_all("/^\\s*emailer\\.app_version: '((?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*))'$/m", $services, $matches)) {
    throw new RuntimeException('Expected one canonical stable rehearsal target.');
}

return $matches[1][0];
