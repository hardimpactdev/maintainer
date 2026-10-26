<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class AppPath
{
    public function __construct(
        public readonly string $path,
        public readonly string $dir,
        public readonly bool $composer,
        public readonly bool $js,
    ) {}
}

final class Config
{
    /** @param list<AppPath> $paths @param list<string> $checks */
    public function __construct(
        public readonly string $root,
        public readonly array $paths,
        public readonly array $checks,
    ) {}

    public static function load(string $root): self
    {
        $real = realpath($root);
        if ($real === false || ! is_dir($real)) {
            throw new Failure('Cannot read the repository root', 70);
        }
        $file = $real.'/maintainer.json';
        if (! is_file($file)) {
            return self::implicit($real);
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new Failure('Cannot read maintainer.json', 70);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new Failure('maintainer.json must be an object', 70);
        }
        if (array_key_exists('sides', $data)) {
            throw new Failure('maintainer.json sides is no longer valid. Use paths.', 70);
        }
        $allowed = ['paths', 'checks', '$schema'];
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new Failure('maintainer.json has an unknown key: '.$key, 70);
            }
        }
        if (isset($data['$schema']) && ! is_string($data['$schema'])) {
            throw new Failure('maintainer.json $schema must be a string', 70);
        }
        if (! isset($data['paths']) || ! is_array($data['paths']) || $data['paths'] === [] || array_is_list($data['paths']) === false) {
            throw new Failure('maintainer.json needs a paths list', 70);
        }
        $checks = self::checks($data);
        $paths = [];
        $seen = [];
        $seenDir = [];
        foreach ($data['paths'] as $path) {
            if (! is_string($path)) {
                throw new Failure('maintainer.json paths must be a list of directories', 70);
            }
            self::assertRelative($path);
            if (isset($seen[$path])) {
                throw new Failure('maintainer.json repeats path '.$path, 70);
            }
            $seen[$path] = true;
            $resolved = self::resolve($real, $path);
            if (isset($seenDir[$resolved])) {
                throw new Failure('maintainer.json repeats path '.$path, 70);
            }
            $seenDir[$resolved] = true;
            $composer = is_file($resolved.'/composer.json');
            $js = is_file($resolved.'/package.json');
            if (! $composer && ! $js) {
                throw new Failure('Path '.$path.' has no composer.json or package.json', 70);
            }
            $paths[] = new AppPath($path, $resolved, $composer, $js);
        }

        return new self($real, $paths, $checks);
    }

    /** @return list<AppPath> */
    public function selected(?string $path): array
    {
        if ($path === null) {
            return $this->paths;
        }
        self::assertRelative($path);
        foreach ($this->paths as $place) {
            if ($place->path === $path) {
                return [$place];
            }
        }
        throw new Failure('Unknown path '.$path, 64);
    }

    public function placeFor(string $path, string $manager): AppPath
    {
        $matches = array_values(array_filter(
            $this->selected($path),
            fn (AppPath $place): bool => $manager === 'composer' ? $place->composer : $place->js,
        ));
        if ($matches === []) {
            throw new Failure('Path '.$path.' has no '.$manager.' project', 64);
        }

        return $matches[0];
    }

    /** @param array<mixed> $data @return list<string> */
    private static function checks(array $data): array
    {
        if (! array_key_exists('checks', $data)) {
            return [];
        }
        if (! is_array($data['checks']) || array_is_list($data['checks']) === false) {
            throw new Failure('maintainer.json checks must be a list of commands', 70);
        }
        $checks = [];
        foreach ($data['checks'] as $command) {
            if (! is_string($command) || $command === '' || str_contains($command, "\0")) {
                throw new Failure('maintainer.json checks must be a list of commands', 70);
            }
            $checks[] = $command;
        }

        return $checks;
    }

    private static function implicit(string $root): self
    {
        $composer = is_file($root.'/composer.json');
        $js = is_file($root.'/package.json');
        if (! $composer && ! $js) {
            throw new Failure('The repository root has no maintainer.json, composer.json, or package.json', 70);
        }

        return new self($root, [new AppPath('.', $root, $composer, $js)], []);
    }

    private static function assertRelative(string $path): void
    {
        if ($path === '.') {
            return;
        }
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new Failure('Path '.$path.' is absolute', 70);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new Failure('Path '.$path.' escapes the repository', 70);
            }
        }
    }

    private static function resolve(string $root, string $path): string
    {
        $full = $path === '.' ? $root : $root.'/'.$path;
        $resolved = realpath($full);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new Failure('Path '.$path.' is missing', 70);
        }
        if ($resolved !== $root && ! str_starts_with($resolved, $root.'/')) {
            throw new Failure('Path '.$path.' escapes the repository', 70);
        }

        return $resolved;
    }
}
