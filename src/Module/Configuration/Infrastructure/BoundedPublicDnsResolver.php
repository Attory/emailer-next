<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\PublicDnsResolver;

/** An isolated, killable DNS lookup: PHP's blocking resolver cannot hold an FPM worker indefinitely. */
final class BoundedPublicDnsResolver implements PublicDnsResolver
{
    public function resolve(string $host, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('Website branding exceeded its total time limit. Your saved appearance is unchanged.');
        }
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException('Automatic website colours require the supported PHP CLI helper. Manual colours remain available.');
        }
        $binary = null;
        foreach (['cli' === PHP_SAPI ? PHP_BINARY : '', PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, '/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, PHP_BINDIR.'/php'] as $candidate) {
            if ('' !== $candidate && is_executable($candidate) && is_file($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if (null === $binary) {
            throw new \RuntimeException('The supported PHP CLI helper is unavailable. Use manual website colours or run the installer health check.');
        }
        $script = '$r=@dns_get_record($argv[1], DNS_A|DNS_AAAA);$a=[];foreach($r===false?[]:$r as $v){$i=$v["ip"]??$v["ipv6"]??null;if(is_string($i))$a[]=$i;}echo json_encode($a);';
        $process = proc_open([$binary, '-n', '-r', $script, $host], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin']);
        if (!\is_resource($process)) {
            throw new \RuntimeException('The website DNS check could not start. Use manual colours or try again.');
        }
        $output = '';
        $limit = min($deadline, microtime(true) + 2.0);
        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            while (true) {
                if (microtime(true) >= $limit || 8192 < \strlen($output)) {
                    throw new \RuntimeException('The website DNS check exceeded its time or size limit. Your saved appearance is unchanged.');
                }
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2], 1024);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    break;
                }
                usleep(10000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $addresses = json_decode($output, true);
        if (!\is_array($addresses) || !array_is_list($addresses) || 32 < \count($addresses)) {
            throw new \RuntimeException('The website DNS result could not be read. Your saved appearance is unchanged.');
        }
        foreach ($addresses as $address) {
            if (!\is_string($address)) {
                throw new \RuntimeException('The website DNS result was invalid.');
            }
        }

        return $addresses;
    }
}
