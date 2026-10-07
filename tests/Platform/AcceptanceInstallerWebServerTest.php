<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AcceptanceInstallerWebServerTest extends TestCase
{
    public function testApachePublicListenerWinsOverUnrelatedActiveNginxAndPreservesIpBinding(): void
    {
        $process = $this->selection("LISTEN 0 511 192.0.2.10:80 0.0.0.0:* users:((\"apache2\",pid=1,fd=4))\nLISTEN 0 511 192.0.2.10:443 0.0.0.0:* users:((\"apache2\",pid=1,fd=5))\nLISTEN 0 511 127.0.0.1:8081 0.0.0.0:* users:((\"nginx\",pid=2,fd=4))");
        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('selected=apache http=192.0.2.10:80 https=192.0.2.10:443', $process->getOutput());
    }

    public function testNginxPublicListenerWinsOverUnrelatedActiveApache(): void
    {
        $process = $this->selection('LISTEN 0 511 192.0.2.10:443 0.0.0.0:* users:(("nginx",pid=2,fd=4))');
        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('selected=nginx', $process->getOutput());
        self::assertStringContainsString('listen 192.0.2.10:443 ssl;', $process->getOutput());
    }

    public function testAmbiguousUnknownAndConflictingChoicesFailBeforeProvisioning(): void
    {
        $both = "LISTEN 0 511 192.0.2.10:443 0.0.0.0:* users:((\"apache2\",pid=1,fd=4))\nLISTEN 0 511 192.0.2.11:443 0.0.0.0:* users:((\"nginx\",pid=2,fd=4))";
        foreach ([[$both, ''], ['', ''], ['LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:(("other",pid=3,fd=4))', ''], ['LISTEN 0 511 0.0.0.0:443 0.0.0.0:* users:(("apache2",pid=1,fd=4))', 'nginx'], ['', 'invalid']] as [$listeners, $override]) {
            $process = $this->selection($listeners, $override);
            self::assertSame(1, $process->getExitCode());
            self::assertStringNotContainsString('provisioning', $process->getOutput());
        }
    }

    public function testExplicitChoiceIsAcceptedWhenBothServersHaveTheirOwnPublicListeners(): void
    {
        $both = "LISTEN 0 511 192.0.2.10:80 0.0.0.0:* users:((\"apache2\",pid=1,fd=4))\nLISTEN 0 511 192.0.2.10:443 0.0.0.0:* users:((\"apache2\",pid=1,fd=5))\nLISTEN 0 511 192.0.2.11:80 0.0.0.0:* users:((\"nginx\",pid=2,fd=4))\nLISTEN 0 511 192.0.2.11:443 0.0.0.0:* users:((\"nginx\",pid=2,fd=5))";
        $process = $this->selection($both, 'apache');
        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('selected=apache', $process->getOutput());
    }

    private function selection(string $listeners, string $override = ''): Process
    {
        $installer = (string) file_get_contents(\dirname(__DIR__, 2).'/tools/release/install-0.9.1.sh');
        self::assertSame(1, preg_match('/^select_web_server\(\) \{.*?^\}/ms', $installer, $match));
        $function = $match[0] ?? null;
        self::assertIsString($function);
        $script = "set -euo pipefail\nweb_server=''\napache_http_addresses='*:80'\napache_https_addresses='*:443'\nnginx_http_listen='    listen 80;'\nnginx_https_listen='    listen 443 ssl;'\n";
        $script .= 'EMAILER_WEB_SERVER='.escapeshellarg($override)."\n";
        $script .= 'ss() { printf "%s\\n" '.escapeshellarg($listeners)."; }\n";
        $script .= "systemctl() { return 0; }\nnginx() { :; }\napache2ctl() { :; }\napt_install() { echo provisioning; exit 99; }\nfail() { printf '%s\\n' \"\$1\" >&2; exit 1; }\n";
        $script .= $function."\nselect_web_server\nprintf 'selected=%s http=%s https=%s\\n%s\\n%s\\n' \"\$web_server\" \"\$apache_http_addresses\" \"\$apache_https_addresses\" \"\$nginx_http_listen\" \"\$nginx_https_listen\"\n";
        $process = new Process(['bash', '-c', $script]);
        $process->run();

        return $process;
    }
}
