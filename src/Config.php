<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class Side
{
    public function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly bool $composer,
        public readonly bool $js,
    ) {}
}

final class Config
{
    /** @param list<Side> $sides @param list<string> $checks */
    public function __construct(
        public readonly string $root,
        public readonly array $sides,
        public readonly array $checks,
    ) {}

    public static function load(string $root): self
    {
        $real = realpath($root);
        if ($real === false || ! is_dir($real)) {
            throw new Failure('Cannot read the repository root', 70);
        }
        $path = $real.'/maintainer.json';
        if (! is_file($path)) {
            return self::implicit($real);
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new Failure('Cannot read maintainer.json', 70);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new Failure('maintainer.json must be an object', 70);
        }
        $allowed = ['sides', 'checks', '$schema'];
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new Failure('maintainer.json has an unknown key: '.$key, 70);
            }
        }
        if (isset($data['$schema']) && ! is_string($data['$schema'])) {
            throw new Failure('maintainer.json $schema must be a string', 70);
        }
        if (! isset($data['sides']) || ! is_array($data['sides']) || $data['sides'] === [] || array_is_list($data['sides']) === false) {
            throw new Failure('maintainer.json needs a sides list', 70);
        }
        $checks = [];
        if (array_key_exists('checks', $data)) {
            if (! is_array($data['checks']) || array_is_list($data['checks']) === false) {
                throw new Failure('maintainer.json checks must be a list of commands', 70);
            }
            foreach ($data['checks'] as $command) {
                if (! is_string($command) || $command === '' || str_contains($command, "\0")) {
                    throw new Failure('maintainer.json checks must be a list of commands', 70);
                }
                $checks[] = $command;
            }
        }
        $sides = [];
        $names = [];
        foreach ($data['sides'] as $side) {
            if (! is_array($side) || array_is_list($side)) {
                throw new Failure('A maintainer.json side must be an object', 70);
            }
            foreach (array_keys($side) as $key) {
                if (! in_array($key, ['name', 'dir', 'managers'], true)) {
                    throw new Failure('A maintainer.json side has an unknown key: '.$key, 70);
                }
            }
            $name = $side['name'] ?? null;
            $dir = $side['dir'] ?? null;
            $managers = $side['managers'] ?? null;
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name) !== 1 || in_array($name, ['composer', 'js', 'routine', 'detect', 'package', 'check'], true)) {
                throw new Failure('A maintainer.json side name is invalid', 70);
            }
            if (isset($names[$name])) {
                throw new Failure('maintainer.json repeats side '.$name, 70);
            }
            $names[$name] = true;
            if (! is_string($dir) || ! self::relativeDir($dir)) {
                throw new Failure('Side '.$name.' has an invalid dir', 70);
            }
            if (! is_array($managers) || $managers === [] || array_is_list($managers) === false) {
                throw new Failure('Side '.$name.' needs a managers list', 70);
            }
            $composer = false;
            $js = false;
            foreach ($managers as $manager) {
                if ($manager === 'composer') {
                    $composer = true;
                } elseif ($manager === 'js') {
                    $js = true;
                } else {
                    throw new Failure('Side '.$name.' has an unknown manager', 70);
                }
            }
            if (count($managers) !== ($composer ? 1 : 0) + ($js ? 1 : 0)) {
                throw new Failure('Side '.$name.' repeats a manager', 70);
            }
            $full = $dir === '.' ? $real : $real.'/'.$dir;
            $resolved = realpath($full);
            if ($resolved === false || ! is_dir($resolved) || ($resolved !== $real && ! str_starts_with($resolved, $real.'/'))) {
                throw new Failure('Side '.$name.' directory is missing', 70);
            }
            $sides[] = new Side($name, $resolved, $composer, $js);
        }

        return new self($real, $sides, $checks);
    }

    /** @return list<Side> */
    public function selected(?string $name): array
    {
        if ($name === null) {
            return $this->sides;
        }
        foreach ($this->sides as $side) {
            if ($side->name === $name) {
                return [$side];
            }
        }
        throw new Failure('Unknown side '.$name, 64);
    }

    public function sideFor(?string $name, string $manager): Side
    {
        $matches = array_values(array_filter(
            $this->selected($name),
            fn (Side $side): bool => $manager === 'composer' ? $side->composer : $side->js,
        ));
        if ($name !== null) {
            if ($matches === []) {
                throw new Failure('Side '.$name.' has no '.$manager.' project', 64);
            }

            return $matches[0];
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            throw new Failure('No side has a '.$manager.' project', 64);
        }
        throw new Failure('Several sides have '.$manager.'; pass the side name.', 64);
    }

    private static function implicit(string $root): self
    {
        $composer = is_file($root.'/composer.json');
        $js = is_file($root.'/package.json');
        if (! $composer && ! $js) {
            throw new Failure('The repository root has no maintainer.json, composer.json, or package.json', 70);
        }

        return new self($root, [new Side('root', $root, $composer, $js)], []);
    }

    private static function relativeDir(string $dir): bool
    {
        if ($dir === '.') {
            return true;
        }
        if (str_starts_with($dir, '/') || str_contains($dir, '\\')) {
            return false;
        }
        foreach (explode('/', $dir) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
