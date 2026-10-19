<?php

declare(strict_types=1);

if ('1' !== getenv('EMAILER_ISOLATED_REHEARSAL')) {
    throw new RuntimeException('Tamper fixture is isolated-only.');
}
mkdir('/rehearsal/tampered', 0o700);
foreach (glob('/package/emailer-next-1.3.0.zip*') as $source) {
    copy($source, '/rehearsal/tampered/'.basename($source));
}
$file = fopen('/rehearsal/tampered/emailer-next-1.3.0.zip', 'r+b');
if (false === $file) {
    throw new RuntimeException('Disposable package fixture is unavailable.');
}
fseek($file, 64);
$byte = fread($file, 1);
fseek($file, 64);
fwrite($file, chr(ord($byte) ^ 1));
fclose($file);
echo "Disposable package tampered; original signed archive remains unchanged.\n";
