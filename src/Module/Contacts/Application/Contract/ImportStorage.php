<?php

declare(strict_types=1);

namespace App\Module\Contacts\Application\Contract;

interface ImportStorage
{
    /** @return array{token: string, sha256: string, size: int} */
    public function store(string $sourcePath): array;

    /** @return resource */
    public function open(string $token, string $expectedSha256);

    /** @param iterable<string> $lines */
    public function writeReport(string $token, iterable $lines): void;

    /** @return resource */
    public function openReport(string $token);

    public function delete(string $token): void;

    public function deleteReport(string $token): void;
}
