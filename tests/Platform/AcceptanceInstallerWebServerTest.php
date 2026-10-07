<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AcceptanceInstallerWebServerTest extends TestCase
{
    public function testApachePublicListenerWinsOverUnrelatedActiveNginxAndPreservesIpBinding(): void
    {
        $process = $this->selection("LISTEN 0 511 192.0.2.10:80 0.0.0.0:* users:((\"apache2\",pid=1,fd=4))\nLISTEN 0 511 192.0.2.10:443 0.0.0.0:* users:((\"apache2\",pid=1,fd=5))\nLISTEN 0 511 127.0.0.1:8081 0.0.0.0:* users:((\"nginx\",pid=2,fd=4))", '', "192.0.2.10:80 legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:1)\n192.0.2.10:443 legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:5)");
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

    public function testVirtualHostGroupsArePreservedIndependentlyOfListenAddresses(): void
    {
        foreach ([
            ['192.0.2.10', '*:80 legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:1)', '*:80'],
            ['0.0.0.0', '192.0.2.10:80 legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:1)', '192.0.2.10:80'],
            ['[::]', '[2001:db8::10]:80 legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:1)', '[2001:db8::10]:80'],
            ['0.0.0.0', "192.0.2.10:80 is a NameVirtualHost\n default server legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:1)\n port 80 namevhost legacy.example.test (/etc/apache2/sites-enabled/legacy.conf:1)\n192.0.2.11:80 second.example.test (/etc/apache2/sites-enabled/second.conf:1)", '192.0.2.10:80 192.0.2.11:80'],
        ] as [$listen, $dump, $expected]) {
            $process = $this->selection('LISTEN 0 511 '.$listen.':80 0.0.0.0:* users:(("apache2",pid=1,fd=4))', '', $dump);
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('http='.$expected.' https=*:443', $process->getOutput());
        }
    }

    public function testMixedUnreachableAndScopedGroupsRefuseProvisioning(): void
    {
        foreach ([
            ['0.0.0.0', "*:80 legacy.example.test (legacy.conf:1)\n192.0.2.10:80 second.example.test (second.conf:1)"],
            ['192.0.2.10', '192.0.2.11:80 legacy.example.test (legacy.conf:1)'],
            ['[fe80::1%eth0]', '*:80 legacy.example.test (legacy.conf:1)'],
            ['0.0.0.0', '*:* legacy.example.test (legacy.conf:1)'],
        ] as [$listen, $dump]) {
            $process = $this->selection('LISTEN 0 511 '.$listen.':80 0.0.0.0:* users:(("apache2",pid=1,fd=4))', '', $dump);
            self::assertSame(1, $process->getExitCode());
            self::assertStringNotContainsString('provisioning', $process->getOutput());
        }
    }

    public function testMainServerListenerWithoutVirtualHostsIsNotTakenOver(): void
    {
        $process = $this->selection('LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("apache2",pid=1,fd=4))', '', '');
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('without a virtual-host group', $process->getErrorOutput());
        self::assertStringNotContainsString('provisioning', $process->getOutput());
    }

    public function testNginxWildcardIpv6ListenerRetainsItsAddressFamily(): void
    {
        $process = $this->selection('LISTEN 0 511 *:443 *:* users:(("nginx",pid=2,fd=4))');
        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('listen [::]:443 ssl;', $process->getOutput());
    }

    public function testRenderedSitePreservesDefaultsAndFailedValidationRemovesOnlyItsOwnFiles(): void
    {
        $installer = (string) file_get_contents(\dirname(__DIR__, 2).'/tools/release/install-0.9.1.sh');
        foreach (['apache', 'nginx'] as $server) {
            foreach (['success', 'invalid', 'default-changed'] as $outcome) {
                if ('nginx' === $server && 'default-changed' === $outcome) {
                    continue;
                }
                $root = sys_get_temp_dir().'/emailer-web-test-'.bin2hex(random_bytes(8));
                self::assertTrue(mkdir($root.'/'.$server.'/sites-available', 0o700, true));
                self::assertTrue(mkdir($root.'/'.$server.'/sites-enabled', 0o700));
                file_put_contents($root.'/'.$server.'/sites-enabled/legacy.conf', 'unrelated site');
                $script = "set -euo pipefail\nweb_configuration_pending=false\n";
                $script .= 'web_server='.escapeshellarg($server)."\n";
                $script .= "apache_http_addresses='*:80'\napache_https_addresses='*:443'\nnginx_http_listen='    listen [::]:80;'\nnginx_https_listen='    listen [::]:443 ssl;'\nEMAILER_HOSTNAME=mailer.example.test\nEMAILER_TLS_CERT=/synthetic/cert\nEMAILER_TLS_KEY=/synthetic/key\n";
                $script .= 'apache_vhost_defaults_before='.escapeshellarg("*:80\tlegacy.example.test (legacy.conf:1)")."\n";
                $script .= "fail() { printf '%s\\n' \"\$1\" >&2; exit 1; }\na2enmod() { :; }\nsystemctl() { echo service-action; }\n";
                $script .= 'nginx() { return '.('invalid' === $outcome ? '1' : '0')."; }\n";
                $dump = 'default-changed' === $outcome ? '*:80 mailer.example.test (emailer-next.conf:1)' : "*:80 is a NameVirtualHost\n default server legacy.example.test (legacy.conf:1)\n port 80 namevhost mailer.example.test (emailer-next.conf:1)";
                $script .= 'apache2ctl() { if [ "$1" = configtest ]; then return '.('invalid' === $outcome ? '1' : '0').'; fi; printf "%s\\n" '.escapeshellarg($dump)."; }\n";
                foreach (['cleanup_web_configuration', 'apache_vhost_defaults', 'validate_and_reload_web_server'] as $helper) {
                    self::assertSame(1, preg_match('/^'.$helper.'\(\) \{.*?^\}/ms', $installer, $match));
                    $helperFunction = $match[0] ?? null;
                    self::assertIsString($helperFunction);
                    $script .= $helperFunction."\n";
                }
                $start = strpos($installer, "configure_web_server() {\n");
                $end = strpos($installer, "\n[ \"$(id -u)\"", false === $start ? 0 : $start);
                self::assertIsInt($start);
                self::assertIsInt($end);
                $script .= substr($installer, $start, $end - $start)."\ntrap cleanup_web_configuration EXIT\nconfigure_web_server\nvalidate_and_reload_web_server\n";
                $script = str_replace(['/etc/apache2', '/etc/nginx', '/etc/$('], [$root.'/apache', $root.'/nginx', $root.'/$('], $script);
                // The dynamic directory expression uses the distribution's apache2 name.
                $script = str_replace('printf apache2', 'printf apache', $script);
                $process = new Process(['bash', '-c', $script]);
                $process->run();
                $site = $root.'/'.$server.'/sites-available/emailer-next.conf';
                $link = $root.'/'.$server.'/sites-enabled/'.('apache' === $server ? 'zzzz-emailer-next.conf' : 'emailer-next.conf');
                try {
                    self::assertSame('success' === $outcome ? 0 : 1, $process->getExitCode(), $process->getErrorOutput());
                    self::assertSame('unrelated site', file_get_contents($root.'/'.$server.'/sites-enabled/legacy.conf'));
                    if ('success' === $outcome) {
                        self::assertTrue(is_link($link));
                        self::assertStringContainsString('apache' === $server ? '<VirtualHost *:80>' : 'listen [::]:80;', (string) file_get_contents($site));
                    } else {
                        self::assertFileDoesNotExist($site);
                        self::assertFalse(is_link($link));
                        self::assertStringNotContainsString('service-action', $process->getOutput());
                    }
                } finally {
                    if (is_link($link)) {
                        unlink($link);
                    }
                    if (is_file($site)) {
                        unlink($site);
                    }
                    unlink($root.'/'.$server.'/sites-enabled/legacy.conf');
                    rmdir($root.'/'.$server.'/sites-enabled');
                    rmdir($root.'/'.$server.'/sites-available');
                    rmdir($root.'/'.$server);
                    rmdir($root);
                }
            }
        }
    }

    private function selection(string $listeners, string $override = '', ?string $vhosts = null): Process
    {
        if (null === $vhosts) {
            $vhosts = '';
            if (str_contains($listeners, '"apache2"')) {
                foreach ([80, 443] as $port) {
                    if (str_contains($listeners, ':'.$port.' ')) {
                        $vhosts .= '*:'.$port." legacy.example.test (legacy.conf:1)\n";
                    }
                }
            }
        }
        $installer = (string) file_get_contents(\dirname(__DIR__, 2).'/tools/release/install-0.9.1.sh');
        self::assertSame(1, preg_match('/^select_web_server\(\) \{.*?^\}/ms', $installer, $match));
        $function = $match[0] ?? null;
        self::assertIsString($function);
        $script = "set -euo pipefail\nweb_server=''\napache_http_addresses='*:80'\napache_https_addresses='*:443'\nnginx_http_listen='    listen 80;'\nnginx_https_listen='    listen 443 ssl;'\n";
        $script .= 'EMAILER_WEB_SERVER='.escapeshellarg($override)."\n";
        $script .= 'ss() { printf "%s\\n" '.escapeshellarg($listeners)."; }\n";
        $script .= 'apache2ctl() { printf "%s\\n" '.escapeshellarg($vhosts)."; }\n";
        foreach (['apache_vhost_defaults', 'select_apache_vhost_groups'] as $helper) {
            self::assertSame(1, preg_match('/^'.$helper.'\(\) \{.*?^\}/ms', $installer, $helperMatch));
            $helperFunction = $helperMatch[0] ?? null;
            self::assertIsString($helperFunction);
            $script .= $helperFunction."\n";
        }
        $script .= "systemctl() { return 0; }\nnginx() { :; }\napt_install() { echo provisioning; exit 99; }\nfail() { printf '%s\\n' \"\$1\" >&2; exit 1; }\n";
        $script .= $function."\nselect_web_server\nprintf 'selected=%s http=%s https=%s\\n%s\\n%s\\n' \"\$web_server\" \"\$apache_http_addresses\" \"\$apache_https_addresses\" \"\$nginx_http_listen\" \"\$nginx_https_listen\"\n";
        $process = new Process(['bash', '-c', $script]);
        $process->run();

        return $process;
    }
}
