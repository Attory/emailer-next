<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Target extraction is isolated-only.');
}
$z = new ZipArchive();
if (true !== $z->open('/package/emailer-next-1.3.0.zip') || !mkdir('/rehearsal/target', 0o755) || !$z->extractTo('/rehearsal/target')) {
    throw new RuntimeException('Verified rehearsal target extraction failed.');
}
$z->close();
