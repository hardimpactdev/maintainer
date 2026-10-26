<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

/**
 * Prints a pull-request title and body for the lockfile changes since a base commit.
 */
final class Summary
{
    public function __construct(private readonly Runner $runner) {}

    public function write(Config $config, ?string $base): void
    {
        $resolved = $this->resolveBase($config->root, $base);
        $rows = [];
        foreach ($config->paths as $place) {
            foreach ($this->rowsFor($config->root, $place, $resolved) as $row) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            throw new Failure('No dependency changes to summarize.', 1);
        }
        echo $this->render($rows);
    }

    /** @return list<array{path: string, package: string, from: string, to: string, major: bool, advisories: list<array{id: string, url: ?string}>}> */
    private function rowsFor(string $root, AppPath $place, string $base): array
    {
        $changes = [];
        $direct = [];
        if ($place->composer) {
            $direct['composer'] = $this->composerDirect($place->dir.'/composer.json');
            $before = $this->versions($place, 'composer', $this->show($root, $place, $base, 'composer.lock'));
            $after = $this->versions($place, 'composer', $this->readLock($place->dir.'/composer.lock'));
            $changes['composer'] = $this->diff($before, $after);
        }
        if ($place->js) {
            $js = JsWorkspace::load($place->dir);
            $direct['js'] = $js->direct;
            $lock = $js->manager === 'bun' ? 'bun.lock' : 'package-lock.json';
            $before = $this->versions($place, 'js', $this->show($root, $place, $base, $lock));
            $after = $this->versions($place, 'js', $this->readLock($place->dir.'/'.$lock));
            $changes['js'] = $this->diff($before, $after);
        }
        $advisories = [];
        foreach ($changes as $manager => $diff) {
            if ($diff === []) {
                continue;
            }
            $advisories[$manager] = $this->advisories($root, $place, $manager, $base);
        }
        $rows = [];
        foreach ($changes as $manager => $diff) {
            foreach ($diff as $package => $range) {
                $found = $advisories[$manager][$package] ?? [];
                $isDirect = array_key_exists($package, $direct[$manager] ?? []);
                if (! $isDirect && $found === []) {
                    continue;
                }
                $from = $range[0];
                $to = $range[1];
                $rows[] = [
                    'path' => $place->path,
                    'package' => $package,
                    'from' => $from ?? '—',
                    'to' => $to ?? '—',
                    'major' => $from !== null && $to !== null && JsReports::majorChange($from, $to),
                    'advisories' => $found,
                ];
            }
        }
        usort($rows, fn (array $left, array $right): int => [$left['path'], $left['package']] <=> [$right['path'], $right['package']]);

        return $rows;
    }

    /** @return array<string, list<array{id: string, url: ?string}>> */
    private function advisories(string $root, AppPath $place, string $manager, string $base): array
    {
        $this->runner->prepare($place);
        $current = $this->runner->advisories($place, $manager)[$manager] ?? [];
        $lock = $manager === 'composer'
            ? 'composer.lock'
            : (JsWorkspace::load($place->dir)->manager === 'bun' ? 'bun.lock' : 'package-lock.json');
        $baseText = $this->show($root, $place, $base, $lock);
        $previous = $baseText === null ? [] : $this->auditBase($place, $manager, $baseText)[$manager] ?? [];

        return $this->merge($current, $previous);
    }

    /** @return array{composer?: array<string, list<array<string, mixed>>>, js?: array<string, list<array<string, mixed>>>} */
    private function auditBase(AppPath $place, string $manager, string $lock): array
    {
        $dir = sys_get_temp_dir().'/maintainer-base-'.bin2hex(random_bytes(4));
        if (! mkdir($dir, 0755) && ! is_dir($dir)) {
            throw new Failure('Cannot compare the base lockfile', 70);
        }
        try {
            $composer = $manager === 'composer';
            if ($composer) {
                $this->copy($place->dir.'/composer.json', $dir.'/composer.json');
                file_put_contents($dir.'/composer.lock', $lock);
            } else {
                $js = JsWorkspace::load($place->dir);
                $this->copy($place->dir.'/package.json', $dir.'/package.json');
                $name = $js->manager === 'bun' ? 'bun.lock' : 'package-lock.json';
                file_put_contents($dir.'/'.$name, $lock);
                if ($js->vitePlus) {
                    $vp = $place->dir.'/node_modules/.bin/vp';
                    if (! is_executable($vp)) {
                        throw new Failure('Missing executable node_modules/.bin/vp; install the repository dependencies first.', 127);
                    }
                    if (! mkdir($dir.'/node_modules/.bin', 0755, true) && ! is_dir($dir.'/node_modules/.bin')) {
                        throw new Failure('Cannot compare the base lockfile', 70);
                    }
                    if (! symlink($vp, $dir.'/node_modules/.bin/vp')) {
                        throw new Failure('Cannot compare the base lockfile', 70);
                    }
                }
            }

            return $this->runner->advisories(new AppPath($place->path, $dir, $composer, ! $composer), $manager);
        } finally {
            $this->removeTree($dir);
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $left
     * @param array<string, list<array<string, mixed>>> $right
     * @return array<string, list<array{id: string, url: ?string}>>
     */
    private function merge(array $left, array $right): array
    {
        $byPackage = [];
        foreach ([$left, $right] as $set) {
            foreach ($set as $name => $advisories) {
                if (! is_string($name) || ! is_array($advisories)) {
                    continue;
                }
                foreach ($advisories as $advisory) {
                    if (! is_array($advisory) || ! is_string($advisory['id'] ?? null) || $advisory['id'] === '') {
                        continue;
                    }
                    $url = $advisory['url'] ?? null;
                    $byPackage[$name][$advisory['id']] = [
                        'id' => $advisory['id'],
                        'url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
                    ];
                }
            }
        }
        $merged = [];
        foreach ($byPackage as $name => $advisories) {
            ksort($advisories, SORT_STRING);
            $merged[$name] = array_values($advisories);
        }
        ksort($merged, SORT_STRING);

        return $merged;
    }

    /** @return array<string, string> */
    private function versions(AppPath $place, string $manager, ?string $text): array
    {
        if ($text === null) {
            return [];
        }
        if ($manager === 'composer') {
            return $this->composerVersions($text);
        }
        $js = JsWorkspace::load($place->dir);

        return $js->manager === 'bun' ? $this->bunVersions($text) : $this->npmVersions($text);
    }

    /** @return array<string, string> */
    private function composerVersions(string $text): array
    {
        $data = $this->decode($text, 'composer.lock');
        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            if (! isset($data[$section])) {
                continue;
            }
            if (! is_array($data[$section])) {
                throw new Failure('composer.lock has no '.$section.' list', 70);
            }
            foreach ($data[$section] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null) || $package['version'] === '') {
                    throw new Failure('composer.lock entry is incomplete', 70);
                }
                $versions[$package['name']] = $this->normalize($package['version']);
            }
        }

        return $versions;
    }

    /** @return array<string, string> */
    private function npmVersions(string $text): array
    {
        $data = $this->decode($text, 'package-lock.json');
        if (! isset($data['packages']) || ! is_array($data['packages'])) {
            throw new Failure('package-lock.json has no packages field', 70);
        }
        $best = [];
        foreach ($data['packages'] as $path => $package) {
            if (! is_string($path) || ! is_array($package)) {
                continue;
            }
            $name = $this->npmName($path);
            if ($name === null || ! is_string($package['version'] ?? null) || $package['version'] === '') {
                continue;
            }
            $depth = substr_count($path, 'node_modules/');
            if (! isset($best[$name]) || $depth < $best[$name]['depth']) {
                $best[$name] = ['depth' => $depth, 'version' => $this->normalize($package['version'])];
            }
        }
        $versions = [];
        foreach ($best as $name => $row) {
            $versions[$name] = $row['version'];
        }

        return $versions;
    }

    /** @return array<string, string> */
    private function bunVersions(string $text): array
    {
        $stripped = preg_replace('/,\s*([}\]])/', '$1', $text);
        $data = $this->decode(is_string($stripped) ? $stripped : '', 'bun.lock');
        if (! isset($data['packages']) || ! is_array($data['packages'])) {
            throw new Failure('bun.lock has no packages field', 70);
        }
        $versions = [];
        foreach ($data['packages'] as $name => $entry) {
            if (! is_string($name) || ! is_array($entry) || ! is_string($entry[0] ?? null)) {
                throw new Failure('Invalid bun.lock package entry', 70);
            }
            $prefix = $name.'@';
            if (! str_starts_with($entry[0], $prefix)) {
                throw new Failure('Invalid bun.lock package entry', 70);
            }
            $version = substr($entry[0], strlen($prefix));
            if ($version === '') {
                throw new Failure('Invalid bun.lock package entry', 70);
            }
            $versions[$name] = $this->normalize($version);
        }

        return $versions;
    }

    private function npmName(string $path): ?string
    {
        if ($path === '' || ! str_contains($path, 'node_modules/')) {
            return null;
        }
        $name = substr($path, (int) strrpos($path, 'node_modules/') + strlen('node_modules/'));
        if ($name === '' || str_contains($name, '/node_modules/')) {
            return null;
        }
        $slashes = substr_count($name, '/');
        if ($name[0] === '@') {
            return $slashes === 1 ? $name : null;
        }

        return $slashes === 0 ? $name : null;
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private function diff(array $before, array $after): array
    {
        $names = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($names, SORT_STRING);
        $changes = [];
        foreach ($names as $name) {
            $from = $before[$name] ?? null;
            $to = $after[$name] ?? null;
            if ($from !== $to) {
                $changes[$name] = [$from, $to];
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function composerDirect(string $path): array
    {
        $data = $this->decode((string) file_get_contents($path), 'composer.json');
        $direct = [];
        foreach (['require', 'require-dev'] as $section) {
            if (! isset($data[$section])) {
                continue;
            }
            if (! is_array($data[$section])) {
                throw new Failure('Cannot read composer.json', 70);
            }
            foreach ($data[$section] as $name => $constraint) {
                if (is_string($name) && $name !== 'php' && ! str_starts_with($name, 'ext-')) {
                    $direct[$name] = $constraint;
                }
            }
        }

        return $direct;
    }

    /** @param list<array{path: string, package: string, from: string, to: string, major: bool, advisories: list<array{id: string, url: ?string}>}> $rows */
    private function render(array $rows): string
    {
        $major = array_any($rows, fn (array $row): bool => $row['major']);
        $security = array_any($rows, fn (array $row): bool => $row['advisories'] !== []);
        $title = count($rows) === 1
            ? 'Update '.$rows[0]['package'].' to '.$rows[0]['to'].($rows[0]['advisories'] !== [] ? ' (security fix)' : '')
            : 'Update dependencies for '.(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('F Y');
        $what = $this->what($rows, $major, $security);
        $meaning = $major
            ? 'This includes a major upgrade. Read the upgrade notes before you deploy.'
            : 'Nothing changes for users of the app.';
        $paths = array_values(array_unique(array_column($rows, 'path')));
        $showPath = count($paths) > 1 || $paths !== ['.'];
        $table = ["| Package | From | To | note |", "| --- | --- | --- | --- |"];
        foreach ($rows as $row) {
            $package = $showPath && $row['path'] !== '.' ? $row['package'].' ('.$row['path'].')' : $row['package'];
            $table[] = '| '.$this->cell($package).' | '.$this->cell($row['from']).' | '.$this->cell($row['to']).' | '.$this->cell($this->note($row)).' |';
        }

        return $title."\n\n".$what."\n\n".$meaning."\n\n".implode("\n", $table)."\n\nTested: compared the lockfiles with the base commit.\n";
    }

    /** @param list<array{path: string, package: string, from: string, to: string, major: bool, advisories: list<array{id: string, url: ?string}>}> $rows */
    private function what(array $rows, bool $major, bool $security): string
    {
        if (count($rows) === 1) {
            $row = $rows[0];
            $sentence = 'Updated '.$row['package'].' from '.$row['from'].' to '.$row['to'];
            if ($row['advisories'] !== []) {
                return $sentence.' to fix a security advisory.';
            }

            return $sentence.'.';
        }
        $paths = array_values(array_unique(array_column($rows, 'path')));
        $where = '';
        if ($paths !== ['.']) {
            $where = ' in '.implode(' and ', array_map(fn (string $path): string => '`'.$path.'`', $paths));
        }
        $extra = [];
        if ($security) {
            $extra[] = 'a security fix';
        }
        if ($major) {
            $extra[] = 'a major upgrade';
        }
        $including = $extra === [] ? '' : ', including '.implode(' and ', $extra);

        return 'Updated '.count($rows).' packages'.$where.$including.'.';
    }

    /** @param array{major: bool, advisories: list<array{id: string, url: ?string}>} $row */
    private function note(array $row): string
    {
        $parts = [];
        if ($row['major']) {
            $parts[] = 'major';
        }
        if ($row['advisories'] !== []) {
            $links = [];
            foreach ($row['advisories'] as $advisory) {
                $links[] = $advisory['url'] === null ? $advisory['id'] : '['.$advisory['id'].']('.$advisory['url'].')';
            }
            $parts[] = 'security fix '.implode(', ', $links);
        }

        return implode('; ', $parts);
    }

    private function cell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    private function normalize(string $version): string
    {
        $version = preg_replace('/\s*\*$/', '', trim($version)) ?? trim($version);

        return preg_replace('/^v(?=\d)/', '', $version) ?? $version;
    }

    private function resolveBase(string $root, ?string $base): string
    {
        if ($base === null) {
            $merged = $this->git($root, ['merge-base', 'HEAD', 'origin/HEAD'], true);
            if ($merged === null) {
                throw new Failure('Pass --base. Cannot find a merge base with origin/HEAD.', 70);
            }
            $base = trim($merged);
        } else {
            $this->assertRef($base);
        }
        $resolved = $this->git($root, ['rev-parse', '--verify', '--end-of-options', $base.'^{commit}'], true);
        if ($resolved === null) {
            throw new Failure('Cannot resolve base '.$base.'.', 70);
        }

        return trim($resolved);
    }

    private function assertRef(string $base): void
    {
        if ($base === '' || str_starts_with($base, '-') || preg_match('/^[A-Za-z0-9._\/-]+$/D', $base) !== 1 || str_contains($base, '..')) {
            throw new Failure('Invalid base ref', 64);
        }
    }

    private function show(string $root, AppPath $place, string $base, string $file): ?string
    {
        $relative = $place->path === '.' ? $file : $place->path.'/'.$file;
        [$code, $text, $error] = (new Process($root))->result(['git', 'show', '--no-textconv', $base.':'.$relative]);
        if ($code === 0) {
            return $text;
        }
        if (str_contains($error, 'does not exist') || str_contains($error, 'exists on disk, but not in')) {
            return null;
        }
        throw new Failure('Cannot read '.$relative.' at the base commit.', 70);
    }

    private function readLock(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $text = file_get_contents($path);
        if (! is_string($text)) {
            throw new Failure('Cannot read '.basename($path), 70);
        }

        return $text;
    }

    /** @return array<mixed> */
    private function decode(string $text, string $name): array
    {
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('Cannot read '.$name, 70);
        }
        if (! is_array($data)) {
            throw new Failure('Cannot read '.$name, 70);
        }

        return $data;
    }

    /** @param list<string> $args */
    private function git(string $root, array $args, bool $missing): ?string
    {
        [$code, $text, $error] = (new Process($root))->result(['git', ...$args]);
        if ($code === 0) {
            return $text;
        }
        if ($missing) {
            return null;
        }
        throw new Failure('Git failed: '.trim($error), 70);
    }

    private function copy(string $from, string $to): void
    {
        if (! is_file($from) || ! copy($from, $to)) {
            throw new Failure('Cannot compare the base lockfile', 70);
        }
    }

    private function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->removeTree($path);
            }
        }
        rmdir($dir);
    }
}
