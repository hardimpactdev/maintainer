<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

/**
 * Maps a declared dependency name to the package it installs when they differ.
 * npm and Bun both use this for aliases such as alias -> lodash.
 */
final class Aliases
{
    /**
     * @param array<mixed> $packages
     * @return array<string, string> declared name => installed package
     */
    public static function fromPackages(array $packages, string $manager): array
    {
        $map = [];
        foreach ($packages as $key => $entry) {
            if (! is_string($key) || $key === '' || ! is_array($entry)) {
                continue;
            }
            if ($manager === 'bun') {
                $declared = self::bunDeclared($key);
                $raw = $entry[0] ?? null;
            } else {
                $declared = self::declaredFromNode($key);
                $raw = $entry['name'] ?? null;
                if (! is_string($raw) || $raw === '') {
                    $raw = $entry['version'] ?? null;
                    if (! is_string($raw) || ! str_starts_with($raw, 'npm:')) {
                        continue;
                    }
                }
            }
            if ($declared === null || ! is_string($raw) || $raw === '') {
                continue;
            }
            $installed = self::installedName($raw);
            if ($installed !== $declared) {
                $map[$declared] = $installed;
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * Declared aliases that install $installed. A direct package keeps its own advisories.
     *
     * @param array<string, mixed> $direct
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public static function buckets(string $installed, array $direct, array $aliases): array
    {
        if (array_key_exists($installed, $direct)) {
            return [];
        }
        $buckets = [];
        foreach ($aliases as $declared => $target) {
            if ($target === $installed && array_key_exists($declared, $direct)) {
                $buckets[] = $declared;
            }
        }
        sort($buckets, SORT_STRING);

        return $buckets;
    }

    public static function installedName(string $descriptor): string
    {
        if (str_starts_with($descriptor, 'npm:')) {
            $descriptor = substr($descriptor, 4);
        }
        $at = self::versionAt($descriptor);
        if ($at === null) {
            if ($descriptor === '') {
                throw new Failure('Invalid package descriptor', 70);
            }

            return $descriptor;
        }
        $name = substr($descriptor, 0, $at);
        $version = substr($descriptor, $at + 1);
        if ($name === '' || $version === '') {
            throw new Failure('Invalid package descriptor', 70);
        }

        return $name;
    }

    private static function versionAt(string $descriptor): ?int
    {
        if (str_starts_with($descriptor, '@')) {
            $slash = strpos($descriptor, '/');
            if ($slash === false) {
                return null;
            }
            $at = strpos($descriptor, '@', $slash + 1);

            return $at === false ? null : $at;
        }
        $at = strpos($descriptor, '@');

        return ($at === false || $at === 0) ? null : $at;
    }

    public static function declaredFromNode(string $path): ?string
    {
        if (substr_count($path, 'node_modules/') !== 1 || ! str_starts_with($path, 'node_modules/')) {
            return null;
        }
        $name = substr($path, strlen('node_modules/'));
        if ($name === '') {
            return null;
        }
        if (str_starts_with($name, '@')) {
            return substr_count($name, '/') === 1 ? $name : null;
        }

        return str_contains($name, '/') ? null : $name;
    }

    private static function bunDeclared(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        if (str_starts_with($key, '@')) {
            return substr_count($key, '/') === 1 ? $key : null;
        }

        return str_contains($key, '/') ? null : $key;
    }
}
