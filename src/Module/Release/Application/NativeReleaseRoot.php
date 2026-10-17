<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** A converted installation retains its edition-qualified immutable source root. */
final class NativeReleaseRoot
{
    public static function source(string $releases, string $version, mixed $recorded): string
    {
        $parent = realpath($releases);
        if (false === $parent || !\is_string($recorded) || !\in_array($recorded, [$parent.'/'.$version, $parent.'/ripe-'.$version], true) || is_link($recorded) || !is_dir($recorded) || realpath($recorded) !== $recorded) {
            throw new \RuntimeException('The recorded native source is outside its exact immutable release directory.');
        }

        return $recorded;
    }
}
