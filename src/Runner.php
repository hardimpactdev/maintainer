<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class Runner
{
    public function routine(Config $config, ?string $sideName): void
    {
        $sides = $config->selected($sideName);
        foreach ($sides as $side) {
            $this->preflight($side);
        }
        foreach ($sides as $side) {
            $process = new Process($side->dir);
            if ($side->composer) {
                $process->run(['composer', 'update', '--no-interaction']);
            }
            if ($side->js) {
                $process->run(JsWorkspace::load($side->dir)->updateCommand());
            }
        }
    }

    public function detect(Config $config, ?string $sideName): void
    {
        $sides = $config->selected($sideName);
        foreach ($sides as $side) {
            $this->preflight($side);
        }
        $candidates = [];
        $expo = [];
        foreach ($sides as $side) {
            $process = new Process($side->dir);
            if ($side->composer) {
                $php = $process->json(['composer', 'outdated', '--locked', '--major-only', '--format=json']);
                if (! isset($php['locked']) || ! is_array($php['locked'])) {
                    throw new Failure('Composer outdated has no locked field', 70);
                }
                foreach ($php['locked'] as $item) {
                    if (! is_array($item) || ! is_string($item['name'] ?? null) || ! is_string($item['version'] ?? null) || ! is_string($item['latest'] ?? null)) {
                        throw new Failure('Composer outdated entry is incomplete', 70);
                    }
                    $this->add($candidates, $side->name, 'composer', $item['name'], 'major', [
                        'current' => $item['version'],
                        'latest' => $item['latest'],
                    ]);
                }
            }
            if ($side->js) {
                $js = JsWorkspace::load($side->dir);
                if ($js->expo) {
                    $expo[$side->name] = true;
                }
                foreach ((new JsReports($process))->majors($js) as $item) {
                    $this->add($candidates, $side->name, 'js', $item['name'], 'major', [
                        'current' => $item['current'],
                        'latest' => $item['latest'],
                    ]);
                }
            }
        }
        foreach ($sides as $side) {
            foreach ($this->auditSide($side) as $manager => $packages) {
                foreach ($packages as $name => $advisories) {
                    $this->add($candidates, $side->name, $manager, $name, 'security', ['advisories' => $advisories]);
                }
            }
        }
        $this->foldExpo($candidates, $expo);
        ksort($candidates, SORT_STRING);
        $payload = ['schema' => 1, 'candidates' => array_values($candidates)];
        $stale = (new Policy($config->root))->stale($config);
        if ($stale !== []) {
            $payload['stale_exceptions'] = $stale;
        }
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @param list<string> $ids */
    public function package(Config $config, ?string $sideName, string $manager, string $name, string $version, string $purpose, array $ids): void
    {
        $side = $config->sideFor($sideName, $manager);
        $manifestPath = $side->dir.'/'.($manager === 'composer' ? 'composer.json' : 'package.json');
        $manifest = $this->readJson($manifestPath);
        $js = $manager === 'js' ? JsWorkspace::load($side->dir) : null;
        if ($js !== null && $purpose === 'major' && $name !== 'expo' && $js->expo && Expo::coupled($name)) {
            throw new Failure('Expo SDK and React Native are coupled. Bump expo; expo install --fix aligns this package.');
        }
        $sections = $manager === 'composer'
            ? ['require', 'require-dev']
            : ['dependencies', 'devDependencies', 'optionalDependencies'];
        $found = array_values(array_filter($sections, fn (string $section): bool => isset($manifest[$section][$name])));
        $rangeText = $found === [] ? null : ($manifest[$found[0]][$name] ?? null);
        if (count($found) !== 1 || ! is_string($rangeText) || preg_match('~(?:npm:|workspace:|git|https?:|file:|link:)~', $rangeText) === 1) {
            throw new Failure('Target must be one existing direct registry dependency in exactly one section');
        }
        $this->requireTools($side, $manager, $js, $name);
        $process = new Process($side->dir);
        $range = $purpose === 'security' ? $version : '^'.$version;
        if ($purpose === 'security') {
            $before = $this->advisoriesFor($side, $manager)[$name] ?? [];
            $reported = array_column($before, 'id');
            if (array_diff($ids, $reported) !== [] && ! ($before === [] && $rangeText === $range)) {
                throw new Failure('Requested advisories are not current findings for this package');
            }
            if ($before !== []) {
                $policy = new Policy($config->root);
                if ($manager === 'composer') {
                    $policy->allowComposer($side, $name);
                } elseif ($js !== null && $js->manager === 'bun') {
                    $policy->allowBun($side, $name);
                }
            }
        }
        if ($manager === 'composer') {
            $require = ['composer', 'require', $name.':'.$range, '--no-update', '--no-interaction'];
            if ($found[0] === 'require-dev') {
                $require[] = '--dev';
            }
            $process->run($require);
            $process->run(['composer', 'update', $name, '--with-dependencies', '--no-interaction']);
        } elseif ($js !== null && $js->expo && $name === 'expo') {
            $previous = getenv('npm_config_min_release_age');
            if ($purpose === 'security') {
                putenv('npm_config_min_release_age=0');
            }
            try {
                $process->run(['npx', 'expo', 'install', 'expo@'.$version]);
                $process->run(['npx', 'expo', 'install', '--fix']);
            } finally {
                if ($purpose === 'security') {
                    putenv($previous === false ? 'npm_config_min_release_age' : 'npm_config_min_release_age='.$previous);
                }
            }
        } else {
            $flag = match ($found[0]) {
                'devDependencies', 'require-dev' => '--save-dev',
                'optionalDependencies' => '--save-optional',
                default => '--save-prod',
            };
            if ($js !== null && $js->vitePlus) {
                $add = ['./node_modules/.bin/vp', 'add', $name.'@'.$range, $flag];
                if ($purpose === 'security') {
                    $add[] = '--save-exact';
                    if ($js->manager === 'npm') {
                        array_push($add, '--', '--min-release-age=0');
                    }
                }
            } elseif ($js !== null && $js->manager === 'npm') {
                $add = ['npm', 'install', $name.'@'.$range, $flag];
                if ($purpose === 'security') {
                    array_push($add, '--save-exact', '--min-release-age=0');
                }
            } else {
                $add = ['bun', 'add', $name.'@'.$range];
                if ($found[0] === 'devDependencies') {
                    $add[] = '-d';
                } elseif ($found[0] === 'optionalDependencies') {
                    $add[] = '--optional';
                }
            }
            $process->run($add);
        }
        if ($purpose === 'security' && ($this->advisoriesFor($side, $manager)[$name] ?? []) !== []) {
            throw new Failure('The selected package still has security advisories', 1);
        }
    }

    public function check(Config $config): void
    {
        if ($config->checks === []) {
            echo "No checks configured.\n";

            return;
        }
        $process = new Process($config->root);
        foreach ($config->checks as $command) {
            $process->run(['sh', '-c', $command]);
        }
    }

    private function preflight(Side $side): void
    {
        if ($side->composer) {
            if (! is_file($side->dir.'/composer.json')) {
                throw new Failure('Side '.$side->name.' is missing composer.json', 70);
            }
            $this->onPath('composer');
        }
        if ($side->js) {
            if (! is_file($side->dir.'/package.json')) {
                throw new Failure('Side '.$side->name.' is missing package.json', 70);
            }
            $js = JsWorkspace::load($side->dir);
            if ($js->vitePlus) {
                $this->localVp($side);
            } else {
                $this->onPath($js->manager);
            }
        }
    }

    private function requireTools(Side $side, string $manager, ?JsWorkspace $js, string $name): void
    {
        if ($manager === 'composer') {
            $this->onPath('composer');

            return;
        }
        if ($js === null) {
            throw new Failure('Side '.$side->name.' has no js project', 64);
        }
        if ($js->expo && $name === 'expo') {
            $this->onPath('npx');

            return;
        }
        if ($js->vitePlus) {
            $this->localVp($side);

            return;
        }
        $this->onPath($js->manager);
    }

    private function localVp(Side $side): void
    {
        if (! is_dir($side->dir.'/node_modules')) {
            $this->installJs($side);
        }
        if (! is_executable($side->dir.'/node_modules/.bin/vp')) {
            throw new Failure('Missing executable node_modules/.bin/vp; install the repository dependencies first.', 127);
        }
    }

    private function installJs(Side $side): void
    {
        $js = JsWorkspace::load($side->dir);
        $this->onPath($js->manager);
        $command = $js->manager === 'bun'
            ? ['bun', 'install', '--frozen-lockfile', '--ignore-scripts']
            : ['npm', 'ci', '--ignore-scripts'];
        try {
            (new Process($side->dir))->run($command);
        } catch (CommandFailed $failed) {
            throw new Failure('Frozen '.$js->manager.' install failed for side '.$side->name.'.', $failed->exitCode);
        }
    }

    private function onPath(string $name): void
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            if ($dir !== '' && is_executable($dir.'/'.$name)) {
                return;
            }
        }
        throw new Failure('Missing executable '.$name.' on PATH.', 127);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function advisoriesFor(Side $side, string $manager): array
    {
        $audited = $this->auditSide($side, $manager);

        return $audited[$manager] ?? [];
    }

    /** @return array{composer?: array<string, list<array<string, mixed>>>, js?: array<string, list<array<string, mixed>>>} */
    private function auditSide(Side $side, ?string $only = null): array
    {
        $process = new Process($side->dir);
        $result = [];
        if ($side->composer && ($only === null || $only === 'composer')) {
            $data = $process->json(['composer', 'audit', '--locked', '--format=json']);
            if (! isset($data['advisories']) || ! is_array($data['advisories'])) {
                throw new Failure('Composer audit has no advisories field', 70);
            }
            $advisories = [];
            foreach ($data['advisories'] as $name => $entries) {
                if (! is_string($name) || ! is_array($entries)) {
                    throw new Failure('Composer audit has no advisories field', 70);
                }
                foreach ($entries as $entry) {
                    if (! is_array($entry) || ! is_string($entry['advisoryId'] ?? null) || $entry['advisoryId'] === '') {
                        throw new Failure('Invalid Composer advisory for '.$name, 70);
                    }
                    $advisories[$name][] = [
                        'id' => $entry['advisoryId'],
                        'url' => $entry['link'] ?? null,
                        'title' => $entry['title'] ?? '',
                        'affected' => $entry['affectedVersions'] ?? null,
                    ];
                }
            }
            $result['composer'] = $advisories;
        }
        if ($side->js && ($only === null || $only === 'js')) {
            $js = JsWorkspace::load($side->dir);
            $result['js'] = (new JsReports($process))->advisories($js);
        }

        return $result;
    }

    /** @param array<string, array<string, mixed>> $candidates
     *  @param array<string, mixed> $fields
     */
    private function add(array &$candidates, string $side, string $manager, string $name, string $reason, array $fields): void
    {
        $key = $side.':'.$manager.':'.$name;
        $row = $candidates[$key] ?? [
            'side' => $side,
            'manager' => $manager,
            'package' => $name,
            'reasons' => [],
            'advisories' => [],
        ];
        if (! in_array($reason, $row['reasons'], true)) {
            $row['reasons'][] = $reason;
        }
        $candidates[$key] = array_replace($row, $fields);
    }

    /** @param array<string, array<string, mixed>> $candidates
     *  @param array<string, true> $expoSides
     */
    private function foldExpo(array &$candidates, array $expoSides): void
    {
        foreach ($candidates as $key => $row) {
            if ($row['manager'] !== 'js' || $row['package'] === 'expo' || ! isset($expoSides[$row['side']]) || ! Expo::coupled($row['package'])) {
                continue;
            }
            $reasons = array_values(array_filter($row['reasons'], fn (string $reason): bool => $reason !== 'major'));
            if ($reasons === []) {
                unset($candidates[$key]);

                continue;
            }
            unset($row['current'], $row['latest']);
            $row['reasons'] = $reasons;
            $candidates[$key] = $row;
        }
        foreach ($candidates as $key => $row) {
            if ($row['manager'] === 'js' && $row['package'] === 'expo' && isset($expoSides[$row['side']]) && in_array('major', $row['reasons'], true)) {
                $candidates[$key]['align'] = 'expo-install-fix';
            }
        }
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
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
