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
    /** @return list<array{path: string, package: string, from: string, to: string, major: bool, advisories: list<array{id: string, url: ?string}>}> */
    private function rowsFor(string $root, AppPath $place, string $base): array
    {
        $rows = [];
        if ($place->composer) {
            $rows = array_merge($rows, $this->managerRows($root, $place, $base, 'composer', 'composer.json', 'composer.lock'));
        }
        if ($place->js) {
            $lock = JsWorkspace::load($place->dir)->manager === 'bun' ? 'bun.lock' : 'package-lock.json';
            $rows = array_merge($rows, $this->managerRows($root, $place, $base, 'js', 'package.json', $lock));
        }
        usort($rows, fn (array $left, array $right): int => [$left['path'], $left['package']] <=> [$right['path'], $right['package']]);

        return $rows;
    }

    /** @return list<array{path: string, package: string, from: string, to: string, major: bool, advisories: list<array{id: string, url: ?string}>}> */
    private function managerRows(string $root, AppPath $place, string $base, string $manager, string $manifestName, string $lockName): array
    {
        $currentManifest = $this->readLock($place->dir.'/'.$manifestName);
        $baseManifest = $this->show($root, $place, $base, $manifestName) ?? $currentManifest;
        $directBefore = $this->directNames($manager, $baseManifest);
        $directAfter = $this->directNames($manager, $currentManifest);
        $before = $this->lockView($place, $manager, $this->show($root, $place, $base, $lockName));
        $after = $this->lockView($place, $manager, $this->readLock($place->dir.'/'.$lockName));
        $changed = $directBefore != $directAfter || $before['instances'] != $after['instances'];
        $cleared = $changed ? $this->clearedAdvisories($root, $place, $manager, $base, $lockName, $baseManifest) : [];
        $names = array_unique([...array_keys($directBefore), ...array_keys($directAfter), ...array_keys($cleared)]);
        sort($names, SORT_STRING);
        $rows = [];
        foreach ($names as $package) {
            $was = array_key_exists($package, $directBefore);
            $is = array_key_exists($package, $directAfter);
            $found = $cleared[$package] ?? [];
            if (! $was && ! $is && $found === []) {
                continue;
            }
            if ($was && ! $is) {
                $from = $before['versions'][$package] ?? null;
                $to = null;
            } elseif (! $was && $is) {
                $from = null;
                $to = $after['versions'][$package] ?? null;
            } else {
                $from = $before['versions'][$package] ?? null;
                $to = $after['versions'][$package] ?? null;
            }
            if ($from === $to && $found === []) {
                continue;
            }
            if ($from === null && $to === null && $found === []) {
                continue;
            }
            $rows[] = [
                'path' => $place->path,
                'package' => $package,
                'from' => $from ?? '—',
                'to' => $to ?? '—',
                'major' => $from !== null && $to !== null && JsReports::majorChange($from, $to),
                'advisories' => $found,
            ];
        }

        return $rows;
    }

    /** @return array<string, list<array{id: string, url: ?string}>> */
    private function clearedAdvisories(string $root, AppPath $place, string $manager, string $base, string $lockName, ?string $baseManifest): array
    {
        $this->runner->prepare($place);
        $current = $this->runner->advisories($place, $manager)[$manager] ?? [];
        $baseText = $this->show($root, $place, $base, $lockName);
        $previous = $baseText === null ? [] : $this->auditBase($place, $manager, $baseText, $baseManifest)[$manager] ?? [];

        return $this->onlyCleared($current, $previous);
    }

    /** @return array{composer?: array<string, list<array<string, mixed>>>, js?: array<string, list<array<string, mixed>>>} */
    private function auditBase(AppPath $place, string $manager, string $lock, ?string $manifest): array
    {
        $dir = sys_get_temp_dir().'/maintainer-base-'.bin2hex(random_bytes(4));
        if (! mkdir($dir, 0755) && ! is_dir($dir)) {
            throw new Failure('Cannot compare the base lockfile', 70);
        }
        try {
            $composer = $manager === 'composer';
            if ($composer) {
                $this->writeManifest($place->dir.'/composer.json', $dir.'/composer.json', $manifest);
                file_put_contents($dir.'/composer.lock', $lock);
            } else {
                $name = JsWorkspace::load($place->dir)->manager === 'bun' ? 'bun.lock' : 'package-lock.json';
                $this->writeManifest($place->dir.'/package.json', $dir.'/package.json', $manifest);
                file_put_contents($dir.'/'.$name, $lock);
                $js = JsWorkspace::load($dir);
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
     * @param array<string, list<array<string, mixed>>> $current
     * @param array<string, list<array<string, mixed>>> $previous
     * @return array<string, list<array{id: string, url: ?string}>>
     */
    private function onlyCleared(array $current, array $previous): array
    {
        $present = [];
        foreach ($current as $name => $advisories) {
            if (! is_string($name) || ! is_array($advisories)) {
                continue;
            }
            foreach ($advisories as $advisory) {
                if (is_array($advisory) && is_string($advisory['id'] ?? null) && $advisory['id'] !== '') {
                    $present[$name][$advisory['id']] = true;
                }
            }
        }
        $cleared = [];
        foreach ($previous as $name => $advisories) {
            if (! is_string($name) || ! is_array($advisories)) {
                continue;
            }
            foreach ($advisories as $advisory) {
                if (! is_array($advisory) || ! is_string($advisory['id'] ?? null) || $advisory['id'] === '') {
                    continue;
                }
                if (isset($present[$name][$advisory['id']])) {
                    continue;
                }
                $url = $advisory['url'] ?? null;
                $cleared[$name][$advisory['id']] = [
                    'id' => $advisory['id'],
                    'url' => is_string($url) && str_starts_with($url, 'https://') ? $url : null,
                ];
            }
        }
        $merged = [];
        foreach ($cleared as $name => $advisories) {
            ksort($advisories, SORT_STRING);
            $merged[$name] = array_values($advisories);
        }
        ksort($merged, SORT_STRING);

        return $merged;
    }

    /** @return array{versions: array<string, string>, instances: array<string, string>} */
    private function lockView(AppPath $place, string $manager, ?string $text): array
    {
        if ($text === null) {
            return ['versions' => [], 'instances' => []];
        }
        if ($manager === 'composer') {
            $versions = $this->composerVersions($text);

            return ['versions' => $versions, 'instances' => $versions];
        }
        $js = JsWorkspace::load($place->dir);

        return $js->manager === 'bun' ? $this->bunView($text) : $this->npmView($text);
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

    /** @return array{versions: array<string, string>, instances: array<string, string>} */
    private function npmView(string $text): array
    {
        $data = $this->decode($text, 'package-lock.json');
        if (! isset($data['packages']) || ! is_array($data['packages'])) {
            throw new Failure('package-lock.json has no packages field', 70);
        }
        $versions = [];
        $instances = [];
        foreach ($data['packages'] as $path => $package) {
            if (! is_string($path) || ! is_array($package)) {
                continue;
            }
            $name = $this->npmInstanceName($path);
            if ($name === null || ! is_string($package['version'] ?? null) || $package['version'] === '') {
                continue;
            }
            $version = $this->normalize($package['version']);
            $instances[$path] = $name.'@'.$version;
            if ($path === 'node_modules/'.$name) {
                $versions[$name] = $version;
            }
        }
        ksort($versions, SORT_STRING);
        ksort($instances, SORT_STRING);

        return ['versions' => $versions, 'instances' => $instances];
    }

    /** @return array{versions: array<string, string>, instances: array<string, string>} */
    private function bunView(string $text): array
    {
        $stripped = preg_replace('/,\s*([}\]])/', '$1', $text);
        $data = $this->decode(is_string($stripped) ? $stripped : '', 'bun.lock');
        if (! isset($data['packages']) || ! is_array($data['packages'])) {
            throw new Failure('bun.lock has no packages field', 70);
        }
        $versions = [];
        $instances = [];
        foreach ($data['packages'] as $key => $entry) {
            if (! is_string($key) || ! is_array($entry) || ! is_string($entry[0] ?? null)) {
                throw new Failure('Invalid bun.lock package entry', 70);
            }
            [$name, $version] = $this->bunDescriptor($entry[0]);
            $version = $this->normalize($version);
            $instances[$key] = $name.'@'.$version;
            if (count($this->bunKeyParts($key)) === 1) {
                $versions[$key] = $version;
            }
        }
        ksort($versions, SORT_STRING);
        ksort($instances, SORT_STRING);

        return ['versions' => $versions, 'instances' => $instances];
    }

    /** @return array{0: string, 1: string} */
    private function bunDescriptor(string $descriptor): array
    {
        if (str_starts_with($descriptor, '@')) {
            $slash = strpos($descriptor, '/');
            $at = $slash === false ? false : strpos($descriptor, '@', $slash + 1);
        } else {
            $at = strpos($descriptor, '@');
        }
        if ($at === false || $at === 0) {
            throw new Failure('Invalid bun.lock package entry', 70);
        }
        $name = substr($descriptor, 0, $at);
        $version = substr($descriptor, $at + 1);
        if ($name === '' || $version === '') {
            throw new Failure('Invalid bun.lock package entry', 70);
        }

        return [$name, $version];
    }

    /** @return list<string> */
    private function bunKeyParts(string $key): array
    {
        $parts = [];
        $rest = $key;
        while ($rest !== '') {
            if (str_starts_with($rest, '@')) {
                $slash = strpos($rest, '/');
                if ($slash === false) {
                    throw new Failure('Invalid bun.lock package entry', 70);
                }
                $next = strpos($rest, '/', $slash + 1);
                if ($next === false) {
                    $parts[] = $rest;
                    break;
                }
                $parts[] = substr($rest, 0, $next);
                $rest = substr($rest, $next + 1);

                continue;
            }
            $slash = strpos($rest, '/');
            if ($slash === false) {
                $parts[] = $rest;
                break;
            }
            $parts[] = substr($rest, 0, $slash);
            $rest = substr($rest, $slash + 1);
        }
        if ($parts === [] || in_array('', $parts, true)) {
            throw new Failure('Invalid bun.lock package entry', 70);
        }

        return $parts;
    }

    private function npmInstanceName(string $path): ?string
    {
        $mark = 'node_modules/';
        $at = strrpos($path, $mark);
        if ($at === false) {
            return null;
        }
        $name = substr($path, $at + strlen($mark));
        if ($name === '' || str_contains($name, '/node_modules/')) {
            return null;
        }
        if (str_starts_with($name, '@')) {
            return substr_count($name, '/') === 1 ? $name : null;
        }

        return str_contains($name, '/') ? null : $name;
    }

    /** @return array<string, mixed> */
    private function directNames(string $manager, ?string $text): array
    {
        if ($text === null) {
            return [];
        }
        $label = $manager === 'composer' ? 'composer.json' : 'package.json';
        $data = $this->decode($text, $label);
        $sections = $manager === 'composer'
            ? ['require', 'require-dev']
            : ['dependencies', 'devDependencies', 'optionalDependencies'];
        $direct = [];
        foreach ($sections as $section) {
            if (! isset($data[$section])) {
                continue;
            }
            if (! is_array($data[$section])) {
                throw new Failure('Cannot read '.$label, 70);
            }
            foreach ($data[$section] as $name => $constraint) {
                if (! is_string($name) || $name === '' || $name === 'php' || str_starts_with($name, 'ext-')) {
                    continue;
                }
                $direct[$name] = $constraint;
            }
        }
        ksort($direct, SORT_STRING);

        return $direct;
    }

    /** @param list<array{path: string, package: string, from: string, to: string, major: bool, advisories: list<array{id: string, url: ?string}>}> $rows */
    private function render(array $rows): string
    {
        $major = array_any($rows, fn (array $row): bool => $row['major']);
        $security = array_any($rows, fn (array $row): bool => $row['advisories'] !== []);
        $only = count($rows) === 1 ? $rows[0] : null;
        if ($only !== null && $only['to'] === '—' && $only['from'] !== '—') {
            $title = 'Remove '.$only['package'];
        } elseif ($only !== null && $only['from'] === '—' && $only['to'] !== '—') {
            $title = 'Add '.$only['package'].' '.$only['to'];
        } elseif ($only !== null) {
            $title = 'Update '.$only['package'].' to '.$only['to'].($only['advisories'] !== [] ? ' (security fix)' : '');
        } else {
            $title = 'Update dependencies for '.(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('F Y');
        }
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
            if ($row['to'] === '—' && $row['from'] !== '—') {
                return 'Removed '.$row['package'].' '.$row['from'].'.';
            }
            if ($row['from'] === '—' && $row['to'] !== '—') {
                return 'Added '.$row['package'].' '.$row['to'].'.';
            }
            if ($row['from'] === $row['to'] && $row['advisories'] !== []) {
                return 'Updated '.$row['package'].' to fix a security advisory.';
            }
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

    private function writeManifest(string $from, string $to, ?string $contents): void
    {
        if ($contents !== null) {
            if (file_put_contents($to, $contents) === false) {
                throw new Failure('Cannot compare the base lockfile', 70);
            }

            return;
        }
        $this->copy($from, $to);
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
