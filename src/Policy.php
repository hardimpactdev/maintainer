<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class Policy
{
    public function __construct(private readonly string $root) {}

    public function allowComposer(AppPath $place, string $package): void
    {
        $path = $place->dir.'/vet.json';
        if (! is_file($path)) {
            return;
        }
        $vet = $this->readJson($path, 'vet.json');
        if (! array_key_exists('minimum-release-age', $vet)) {
            return;
        }
        $days = $vet['minimum-release-age'];
        if (! is_int($days) || $days < 0) {
            throw new Failure('vet.json minimum-release-age must be a non-negative integer', 70);
        }
        $exclude = $vet['minimum-release-age-exclude'] ?? [];
        if (! is_array($exclude) || array_is_list($exclude) === false) {
            throw new Failure('vet.json minimum-release-age-exclude must be a list', 70);
        }
        if (! in_array($package, $exclude, true)) {
            $exclude[] = $package;
            $vet['minimum-release-age-exclude'] = array_values($exclude);
            $this->writeJson($path, $vet);
        }
        $this->record($place->path, 'composer', $package, $days);
    }

    public function allowBun(AppPath $place, string $package): void
    {
        $path = $place->dir.'/bunfig.toml';
        if (! is_file($path)) {
            return;
        }
        $text = file_get_contents($path);
        if (! is_string($text) || preg_match('/^\[install\]\s*$/m', $text) !== 1) {
            return;
        }
        if (preg_match('/^minimumReleaseAge\s*=\s*(\d+)\s*$/m', $text, $age) !== 1) {
            return;
        }
        $seconds = (int) $age[1];
        $days = (int) ceil($seconds / 86400);
        if (preg_match('/^minimumReleaseAgeExcludes\s*=/m', $text) === 1
            && preg_match('/^minimumReleaseAgeExcludes\s*=\s*\[(.*)]\s*$/m', $text, $match) !== 1) {
            throw new Failure('bunfig.toml release-age exception is not a single line', 70);
        }
        if (isset($match)) {
            preg_match_all('/"([^"\\\\]*)"/', $match[1], $names);
            $list = $names[1];
            if (! in_array($package, $list, true)) {
                $list[] = $package;
                $rendered = 'minimumReleaseAgeExcludes = ['.implode(', ', array_map(
                    static fn (string $item): string => '"'.$item.'"',
                    $list,
                )).']';
                $updated = preg_replace('/^minimumReleaseAgeExcludes\s*=\s*\[(.*)]\s*$/m', $rendered, $text, 1, $count);
                if (! is_string($updated) || $count !== 1) {
                    throw new Failure('Could not update bunfig.toml release-age exception', 70);
                }
                $text = $updated;
                $this->write($path, $text);
            }
        } else {
            $updated = preg_replace(
                '/^(minimumReleaseAge\s*=.*)$/m',
                "$1\n".'minimumReleaseAgeExcludes = ["'.$package.'"]',
                $text,
                1,
                $count,
            );
            if (! is_string($updated) || $count !== 1) {
                throw new Failure('Could not update bunfig.toml release-age exception', 70);
            }
            $this->write($path, $updated);
        }
        $this->record($place->path, 'js', $package, $days);
    }

    /** @return list<array{path: string, manager: string, package: string, recorded_on: string, remove_on: string}> */
    public function stale(Config $config): array
    {
        $records = $this->records();
        if ($records === []) {
            return [];
        }
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $excluded = $this->currentExclusions($config);
        $stale = [];
        foreach ($records as $record) {
            $key = $record['path']."\0".$record['manager']."\0".$record['package'];
            if (! isset($excluded[$key])) {
                continue;
            }
            $recorded = \DateTimeImmutable::createFromFormat('!Y-m-d', $record['recorded_on'], new \DateTimeZone('UTC'));
            if ($recorded === false) {
                throw new Failure('maintainer-exceptions.json has an invalid date', 70);
            }
            $remove = $recorded->modify('+'.$record['days'].' days');
            if ($today < $remove) {
                continue;
            }
            $stale[] = [
                'path' => $record['path'],
                'manager' => $record['manager'],
                'package' => $record['package'],
                'recorded_on' => $record['recorded_on'],
                'remove_on' => $remove->format('Y-m-d'),
            ];
        }

        return $stale;
    }

    private function record(string $appPath, string $manager, string $package, int $days): void
    {
        $path = $this->root.'/maintainer-exceptions.json';
        $document = is_file($path) ? $this->readJson($path, 'maintainer-exceptions.json') : ['schema' => 1, 'exceptions' => []];
        if (($document['schema'] ?? null) !== 1 || ! isset($document['exceptions']) || ! is_array($document['exceptions'])) {
            throw new Failure('Cannot read maintainer-exceptions.json', 70);
        }
        foreach ($document['exceptions'] as $existing) {
            if (is_array($existing) && ($existing['path'] ?? null) === $appPath && ($existing['manager'] ?? null) === $manager && ($existing['package'] ?? null) === $package) {
                return;
            }
        }
        $document['exceptions'][] = [
            'path' => $appPath,
            'manager' => $manager,
            'package' => $package,
            'recorded_on' => gmdate('Y-m-d'),
            'days' => $days,
        ];
        $this->writeJson($path, $document);
    }

    /** @return list<array{path: string, manager: string, package: string, recorded_on: string, days: int}> */
    private function records(): array
    {
        $path = $this->root.'/maintainer-exceptions.json';
        if (! is_file($path)) {
            return [];
        }
        $document = $this->readJson($path, 'maintainer-exceptions.json');
        if (($document['schema'] ?? null) !== 1 || ! isset($document['exceptions']) || ! is_array($document['exceptions']) || array_is_list($document['exceptions']) === false) {
            throw new Failure('Cannot read maintainer-exceptions.json', 70);
        }
        $records = [];
        foreach ($document['exceptions'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null) || ! is_string($entry['manager'] ?? null) || ! is_string($entry['package'] ?? null) || ! is_string($entry['recorded_on'] ?? null) || ! is_int($entry['days'] ?? null)) {
                throw new Failure('Cannot read maintainer-exceptions.json', 70);
            }
            $records[] = [
                'path' => $entry['path'],
                'manager' => $entry['manager'],
                'package' => $entry['package'],
                'recorded_on' => $entry['recorded_on'],
                'days' => $entry['days'],
            ];
        }

        return $records;
    }

    /** @return array<string, true> */
    private function currentExclusions(Config $config): array
    {
        $excluded = [];
        foreach ($config->paths as $place) {
            $vet = $place->dir.'/vet.json';
            if (is_file($vet)) {
                $data = $this->readJson($vet, 'vet.json');
                foreach ($data['minimum-release-age-exclude'] ?? [] as $package) {
                    if (is_string($package)) {
                        $excluded[$place->path."\0composer\0".$package] = true;
                    }
                }
            }
            $bunfig = $place->dir.'/bunfig.toml';
            if (is_file($bunfig)) {
                $text = (string) file_get_contents($bunfig);
                if (preg_match('/^minimumReleaseAgeExcludes\s*=\s*\[(.*)]\s*$/m', $text, $match) === 1) {
                    preg_match_all('/"([^"\\\\]*)"/', $match[1], $names);
                    foreach ($names[1] as $package) {
                        $excluded[$place->path."\0js\0".$package] = true;
                    }
                }
            }
        }

        return $excluded;
    }

    /** @return array<mixed> */
    private function readJson(string $path, string $label): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new Failure('Cannot read '.$label, 70);
        }
        if (! is_array($data)) {
            throw new Failure('Cannot read '.$label, 70);
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function write(string $path, string $text): void
    {
        if (file_put_contents($path, $text) === false) {
            throw new Failure('Could not write '.basename($path), 70);
        }
    }
}
