<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class JsWorkspace
{
    /** @param array<string, mixed> $direct */
    public function __construct(
        public readonly string $dir,
        public readonly string $manager,
        public readonly bool $vitePlus,
        public readonly array $direct,
        public readonly bool $expo,
    ) {}

    public static function load(string $dir): self
    {
        $manifest = self::read($dir.'/package.json');
        $declared = $manifest['packageManager'] ?? '';
        if (is_string($declared) && str_starts_with($declared, 'npm@')) {
            $manager = 'npm';
        } elseif (is_string($declared) && str_starts_with($declared, 'bun@')) {
            $manager = 'bun';
        } else {
            throw new Failure('package.json packageManager must declare npm@ or bun@', 70);
        }
        $direct = array_replace(
            is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [],
            is_array($manifest['devDependencies'] ?? null) ? $manifest['devDependencies'] : [],
            is_array($manifest['optionalDependencies'] ?? null) ? $manifest['optionalDependencies'] : [],
        );

        return new self($dir, $manager, array_key_exists('vite-plus', $direct), $direct, array_key_exists('expo', $direct));
    }

    /** @return list<string> */
    public function outdatedCommand(): array
    {
        if ($this->vitePlus) {
            return ['./node_modules/.bin/vp', 'outdated', '--format', 'json'];
        }

        return $this->manager === 'npm' ? ['npm', 'outdated', '--json'] : ['bun', 'outdated'];
    }

    /** @return list<string> */
    public function auditCommand(): array
    {
        if ($this->vitePlus) {
            return ['./node_modules/.bin/vp', 'pm', 'audit', '--json'];
        }

        return $this->manager === 'npm' ? ['npm', 'audit', '--json'] : ['bun', 'pm', 'audit', '--json'];
    }

    /** @return list<string> */
    public function updateCommand(): array
    {
        if ($this->vitePlus) {
            return ['./node_modules/.bin/vp', 'update'];
        }

        return [$this->manager, 'update'];
    }

    /** @return array<mixed> */
    private static function read(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new Failure('Cannot read '.basename($path), 70);
        }
        if (! is_array($data)) {
            throw new Failure('Cannot read '.basename($path), 70);
        }

        return $data;
    }
}

final class Expo
{
    public static function coupled(string $name): bool
    {
        return $name === 'expo'
            || $name === 'react'
            || $name === 'react-dom'
            || $name === 'react-native'
            || str_starts_with($name, 'expo-')
            || str_starts_with($name, '@expo/')
            || str_starts_with($name, 'react-native-')
            || str_starts_with($name, '@react-native/')
            || str_starts_with($name, '@react-native-community/');
    }
}

final class JsReports
{
    public function __construct(private readonly Process $process) {}

    /** @param array<string, mixed> $direct
     *  @return list<array{name: string, current: string, latest: string}>
     */
    public function majors(JsWorkspace $js): array
    {
        $args = $js->outdatedCommand();
        [$code, $text, $error] = $this->process->capture($args);
        $this->process->guard($args, $code, $text, $error);
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            if ($js->manager !== 'bun') {
                throw new Failure('Invalid JSON from '.implode(' ', $args).': '.$error, 70);
            }

            return $this->fromTable($text, $js->direct);
        }
        if (! is_array($data) || array_key_exists('error', $data)) {
            throw new Failure('Dependency command failed: '.implode(' ', $args).': '.$error, 70);
        }

        return $this->fromJson($data, $js->direct);
    }

    /**
     * @return array<string, list<array{id: string, url: string, title: string, affected: mixed, severity?: mixed}>>
     */
    public function advisories(JsWorkspace $js): array
    {
        $data = $this->process->json($js->auditCommand());
        if ($js->manager === 'bun') {
            return $this->bunAdvisories($data, $js);
        }
        if (! isset($data['vulnerabilities']) && ! isset($data['advisories'])) {
            throw new Failure('JavaScript audit has no vulnerabilities/advisories field', 70);
        }
        $result = [];
        $direct = $js->direct;
        foreach (array_intersect_key($data['vulnerabilities'] ?? [], $direct) as $name => $item) {
            $result[$name] = $this->npmAdvisories($data['vulnerabilities'], (string) $name);
        }
        foreach ($data['advisories'] ?? [] as $item) {
            if (! is_array($item) || ! is_string($item['module_name'] ?? null)) {
                throw new Failure('Invalid npm advisory', 70);
            }
            $name = $item['module_name'];
            $result[$name][] = [
                'id' => (string) ($item['id'] ?? ''),
                'url' => $item['url'] ?? null,
                'title' => $item['title'] ?? '',
                'affected' => $item['vulnerable_versions'] ?? null,
            ];
        }

        return $result;
    }

    /** @param array<mixed> $vulnerabilities
     *  @return list<array{id: string, url: string, title: string, affected: mixed, severity: mixed}>
     */
    private function npmAdvisories(array $vulnerabilities, string $name): array
    {
        $pending = [$name];
        $seen = [];
        $result = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $viaEntries = $vulnerabilities[$current]['via'] ?? null;
            if (! is_array($viaEntries)) {
                throw new Failure('Cannot resolve npm advisory reference: '.$current, 70);
            }
            foreach ($viaEntries as $via) {
                if (is_string($via)) {
                    $pending[] = $via;
                } elseif (is_array($via) && isset($via['source'], $via['url'])
                    && (is_int($via['source']) || is_string($via['source'])) && (string) $via['source'] !== '' && is_string($via['url'])) {
                    $id = (string) $via['source'];
                    $result[$id] = [
                        'id' => $id,
                        'url' => $via['url'],
                        'title' => $via['title'] ?? '',
                        'affected' => $via['range'] ?? null,
                        'severity' => $via['severity'] ?? null,
                    ];
                } else {
                    throw new Failure('Invalid npm advisory for '.$current, 70);
                }
            }
        }
        if ($result === []) {
            throw new Failure('No resolvable npm advisory for '.$name, 70);
        }

        return array_values($result);
    }

    /**
     * @param array<mixed> $data
     * @return array<string, list<array{id: string, url: string, title: string, affected: mixed, severity: mixed}>>
     */
    private function bunAdvisories(array $data, JsWorkspace $js): array
    {
        if ($data === []) {
            return [];
        }
        if (array_is_list($data)) {
            throw new Failure('Unsupported Bun audit JSON shape', 70);
        }
        $byPackage = [];
        foreach ($data as $name => $entries) {
            if (! is_string($name) || ! is_array($entries) || ($entries !== [] && array_is_list($entries) === false)) {
                throw new Failure('Unsupported Bun audit JSON shape', 70);
            }
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['id'], $entry['url'])
                    || ! (is_int($entry['id']) || is_string($entry['id'])) || (string) $entry['id'] === '' || ! is_string($entry['url']) || $entry['url'] === '') {
                    throw new Failure('Invalid bun advisory for '.$name, 70);
                }
                $id = (string) $entry['id'];
                $byPackage[$name][$id] = [
                    'id' => $id,
                    'url' => $entry['url'],
                    'title' => $entry['title'] ?? '',
                    'affected' => $entry['vulnerable_versions'] ?? null,
                    'severity' => $entry['severity'] ?? null,
                ];
            }
        }
        if ($byPackage === []) {
            return [];
        }
        $directNames = [];
        foreach ($js->direct as $name => $range) {
            if (is_string($name)) {
                $directNames[$name] = true;
            }
        }
        $graph = $this->bunGraph($js->dir);
        $reachable = [];
        foreach (array_keys($directNames) as $root) {
            $stack = [$root];
            $seen = [];
            while ($stack !== []) {
                $current = array_pop($stack);
                if (isset($seen[$current])) {
                    continue;
                }
                $seen[$current] = true;
                $reachable[$root][$current] = true;
                foreach ($graph[$current] ?? [] as $child) {
                    if (isset($directNames[$child]) && $child !== $root) {
                        continue;
                    }
                    $stack[] = $child;
                }
            }
        }
        $ownersOf = function (string $package) use ($directNames, $reachable): array {
            if (isset($directNames[$package])) {
                return [$package];
            }
            $owners = [];
            foreach (array_keys($directNames) as $root) {
                if (isset($reachable[$root][$package])) {
                    $owners[] = $root;
                }
            }
            if ($owners === []) {
                throw new Failure('Cannot attach bun advisory to a direct dependency: '.$package, 70);
            }

            return $owners;
        };
        $buckets = [];
        $seenIds = [];
        $assign = function (array $owners, array $advisories) use (&$buckets, &$seenIds): void {
            foreach ($owners as $owner) {
                foreach ($advisories as $advisory) {
                    if (isset($seenIds[$owner][$advisory['id']])) {
                        continue;
                    }
                    $seenIds[$owner][$advisory['id']] = true;
                    $buckets[$owner][] = $advisory;
                }
            }
        };
        foreach ($byPackage as $package => $advisories) {
            if (isset($directNames[$package])) {
                $assign([$package], $advisories);
            }
        }
        foreach ($byPackage as $package => $advisories) {
            if (! isset($directNames[$package])) {
                $assign($ownersOf($package), $advisories);
            }
        }
        foreach ($buckets as $name => $advisories) {
            $buckets[$name] = array_values($advisories);
        }

        return $buckets;
    }

    /** @return array<string, list<string>> */
    private function bunGraph(string $dir): array
    {
        $path = $dir.'/bun.lock';
        if (! is_file($path)) {
            throw new Failure('Cannot read bun.lock', 70);
        }
        $text = file_get_contents($path);
        $stripped = preg_replace('/,\s*([}\]])/', '$1', is_string($text) ? $text : '');
        try {
            $data = json_decode(is_string($stripped) ? $stripped : '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('Cannot read bun.lock', 70);
        }
        if (! is_array($data) || ! isset($data['packages']) || ! is_array($data['packages'])) {
            throw new Failure('bun.lock has no packages field', 70);
        }
        $graph = [];
        foreach ($data['packages'] as $name => $entry) {
            if (! is_string($name) || ! is_array($entry)) {
                throw new Failure('Invalid bun.lock package entry', 70);
            }
            $meta = $entry[2] ?? [];
            $deps = [];
            if (is_array($meta)) {
                foreach (['dependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
                    if (! isset($meta[$section]) || ! is_array($meta[$section])) {
                        continue;
                    }
                    foreach ($meta[$section] as $dep => $constraint) {
                        if (! is_string($dep)) {
                            throw new Failure('Invalid bun.lock dependency of '.$name, 70);
                        }
                        $deps[$dep] = true;
                    }
                }
            }
            $graph[$name] = array_keys($deps);
        }

        return $graph;
    }

    /**
     * @param array<mixed> $data
     * @param array<string, mixed> $direct
     * @return list<array{name: string, current: string, latest: string}>
     */
    private function fromJson(array $data, array $direct): array
    {
        $rows = [];
        foreach ($data as $name => $item) {
            if (! is_array($item) || ! isset($item['current'], $item['latest']) || ! is_string($item['current']) || ! is_string($item['latest'])) {
                throw new Failure('Unsupported outdated JSON shape', 70);
            }
            $resolved = $item['name'] ?? $name;
            if (! is_string($resolved) || $resolved === '') {
                throw new Failure('Outdated entry has no package name', 70);
            }
            if (! array_key_exists($resolved, $direct)) {
                continue;
            }
            if ($this->isMajor($item['current'], $item['latest'])) {
                $rows[] = ['name' => $resolved, 'current' => $this->stripAge($item['current']), 'latest' => $this->stripAge($item['latest'])];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $direct
     * @return list<array{name: string, current: string, latest: string}>
     */
    private function fromTable(string $text, array $direct): array
    {
        $rows = [];
        $sawHeader = false;
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($this->noise($trimmed)) {
                continue;
            }
            if (preg_match('/^\|[-| ]+\|$/', $trimmed) === 1) {
                continue;
            }
            if (preg_match('/^\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|$/', $trimmed, $match) !== 1) {
                throw new Failure('Unsupported Bun outdated output', 70);
            }
            $name = $this->directLabel($match[1]);
            if ($name === 'Package') {
                $sawHeader = true;

                continue;
            }
            if (! $sawHeader || $match[2] === '' || $match[4] === '') {
                throw new Failure('Unsupported Bun outdated row', 70);
            }
            if (! array_key_exists($name, $direct)) {
                continue;
            }
            $current = $this->stripAge($match[2]);
            $latest = $this->stripAge($match[4]);
            if (preg_match('/^v?\d+\.\d+\./', $current) !== 1 || preg_match('/^v?\d+\.\d+\./', $latest) !== 1) {
                throw new Failure('Unsupported Bun outdated row', 70);
            }
            if ($this->isMajor($current, $latest)) {
                $rows[] = ['name' => $name, 'current' => $current, 'latest' => $latest];
            }
        }

        return $rows;
    }

    private function isMajor(string $current, string $latest): bool
    {
        $current = $this->stripAge($current);
        $latest = $this->stripAge($latest);

        return preg_match('/^v?(\d+)\.(\d+)\./', $current, $left) === 1
            && preg_match('/^v?(\d+)\.(\d+)\./', $latest, $right) === 1
            && ($left[1] !== $right[1] || ($left[1] === '0' && $left[2] !== $right[2]));
    }

    private function stripAge(string $version): string
    {
        return preg_replace('/\s*\*$/', '', trim($version)) ?? trim($version);
    }

    private function directLabel(string $reported): string
    {
        $reported = trim($reported);

        return preg_replace('/\s+\((?:dev|optional|peer)\)$/', '', $reported) ?? $reported;
    }

    private function noise(string $line): bool
    {
        $lower = strtolower($line);

        return $line === ''
            || str_starts_with($line, 'bun outdated')
            || str_starts_with($line, 'Note:')
            || str_starts_with($lower, 'warning:')
            || str_contains($lower, 'unsupported');
    }
}
