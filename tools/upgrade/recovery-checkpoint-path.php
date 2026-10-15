<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Checkpoint selection is isolated-only.');
}
$r = json_decode((string) file_get_contents('/rehearsal/private/edition-delivery-hold.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_string($r['recovery_directory'] ?? null) || !str_starts_with($r['recovery_directory'], '/rehearsal/recovery/')) {
    throw new RuntimeException('Protected rehearsal checkpoint is unavailable.');
}
echo $r['recovery_directory'];
