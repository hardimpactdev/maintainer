<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

describe('routine', function () {
    it('updates the root and stops at the first failure', function (int $composerExit, int $vpExit) {
        $result = runMaintainer($this->directory, $this->repo, ['routine'], [], [
            'DEPENDENCY_TEST_COMPOSER_EXIT' => (string) $composerExit,
            'DEPENDENCY_TEST_VP_EXIT' => (string) $vpExit,
        ]);

        expect($result->getExitCode())->toBe($composerExit ?: $vpExit);
        $expected = 'composer|update --no-interaction|'.$this->repo."\n";
        if ($composerExit === 0) {
            $expected .= 'vp|update|'.$this->repo."\n";
        }
        expect(commandLog($this->directory))->toBe($expected);
    })->with([[0, 0], [17, 0], [0, 23]]);

    it('fails before updates when local Vite+ is missing or not executable, even with a global vp', function (bool $missing) {
        if ($missing) {
            unlink($this->repo.'/node_modules/.bin/vp');
        } else {
            chmod($this->repo.'/node_modules/.bin/vp', 0644);
        }
        copy(packageRoot().'/tests/Fixtures/dependencies/manager', $this->directory.'/bin/vp');
        chmod($this->directory.'/bin/vp', 0755);
        $result = runMaintainer($this->directory, $this->repo, ['routine']);

        expect($result->getExitCode())->toBe(127)
            ->and($result->getErrorOutput())->toContain('Missing executable node_modules/.bin/vp; install the repository dependencies first.')
            ->and(is_file($this->directory.'/commands'))->toBeFalse();
    })->with([true, false]);

    it('calls the declared manager directly when vite-plus is not installed', function () {
        [$directory, $repo] = newMaintainerRepo(false);
        try {
            $result = runMaintainer($directory, $repo, ['routine']);
            expect($result->getExitCode())->toBe(0)
                ->and(commandLog($directory))->toBe(
                    'composer|update --no-interaction|'.$repo."\n".'npm|update|'.$repo."\n",
                );
        } finally {
            removeMaintainerRepo($directory);
        }
    });

    it('rejects extra arguments', function () {
        $result = runMaintainer($this->directory, $this->repo, ['routine', '--force']);
        expect($result->getExitCode())->toBe(64)
            ->and($result->getErrorOutput())->toContain('maintainer dependencies routine')
            ->and(is_file($this->directory.'/commands'))->toBeFalse();
    });
});

describe('detect and package', function () {
    it('detects locked Composer results without vendor and keeps real audit exit codes', function () {
        expect(is_dir($this->repo.'/vendor'))->toBeFalse();
        $result = runMaintainer($this->directory, $this->repo, ['detect'], [
            'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => [['name' => 'other/pkg', 'version' => '1.0.0', 'latest' => '2.0.0']]]]],
            'vp outdated --format json' => [['code' => 1, 'data' => ['react' => ['current' => '19.0.0', 'latest' => '20.0.0'], 'unchanged' => ['current' => '1.0.0', 'latest' => '1.1.0']]]],
            'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
            'vp pm audit --json' => [['code' => 1, 'data' => ['vulnerabilities' => ['react' => ['via' => [['source' => 123, 'url' => 'https://github.com/advisories/GHSA-test', 'title' => 'test', 'range' => '<20', 'severity' => 'high']]]]]]],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
        $payload = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($payload['schema'])->toBe(1);
        $rows = $payload['candidates'];
        expect(array_column($rows, 'package'))->toBe(['livewire/livewire', 'other/pkg', 'react'])
            ->and($rows[0]['path'])->toBe('.')
            ->and($rows[0]['advisories'])->toHaveCount(2)
            ->and($rows[2]['reasons'])->toBe(['major', 'security'])
            ->and($rows[2]['advisories'][0]['id'])->toBe('123');
        $log = commandLog($this->directory);
        expect($log)->toContain('composer|outdated --locked --major-only --format=json|', 'composer|audit --locked --format=json|', 'vp|outdated --format json|', 'vp|pm audit --json|')
            ->and(is_dir($this->repo.'/vendor'))->toBeFalse();
    });

    it('fails closed on malformed detection output', function (array $response) {
        $result = runMaintainer($this->directory, $this->repo, ['detect'], [
            'composer outdated --locked --major-only --format=json' => [$response],
        ]);
        expect($result->getExitCode())->toBe(70)->and($result->getOutput())->toBe('');
    })->with([[['raw' => 'not json']], [['code' => 4, 'data' => ['locked' => []]]], [['data' => ['error' => 'offline']]], [['data' => []]]]);

    it('updates one Composer range with a targeted update and does not need Vite+', function () {
        unlink($this->repo.'/node_modules/.bin/vp');
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'dev/tool', '2.0.0', 'major']);
        expect($result->getExitCode())->toBe(0);
        $manifest = json_decode((string) file_get_contents($this->repo.'/composer.json'), true);
        expect($manifest['require-dev']['dev/tool'])->toBe('^2.0.0')
            ->and($manifest['require']['other/pkg'])->toBe('^1.0');
        expect(commandLog($this->directory))->toContain('composer|require dev/tool:^2.0.0 --no-update --no-interaction --dev|', 'composer|update dev/tool --with-dependencies --no-interaction|');
    });

    it('keeps the JavaScript section and delegates the range bump to local vp', function () {
        $result = runMaintainer($this->directory, $this->repo, ['package', 'js', '@scope/tool', '2.1.0', 'major']);
        expect($result->getExitCode())->toBe(0)
            ->and(json_decode((string) file_get_contents($this->repo.'/package.json'), true)['devDependencies']['@scope/tool'])->toBe('^2.1.0');
        expect(commandLog($this->directory))->toContain('vp|add @scope/tool@^2.1.0 --save-dev|');
    });

    it('starts a Composer security update without vendor and clears both advisories', function () {
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db', 'PKSA-bgw4-5zmg-2njg'], [
            'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()], ['data' => ['advisories' => []]]],
        ]);
        expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
        expect(substr_count(commandLog($this->directory), 'composer|audit --locked --format=json|'))->toBe(2);
        expect(json_decode((string) file_get_contents($this->repo.'/vet.json'), true)['minimum-release-age-exclude'])->toBe(['existing/pkg', 'livewire/livewire']);
        expect(json_decode((string) file_get_contents($this->repo.'/composer.json'), true)['require']['livewire/livewire'])->toBe('4.9.9');
        $record = json_decode((string) file_get_contents($this->repo.'/maintainer-exceptions.json'), true);
        expect($record['exceptions'][0]['package'])->toBe('livewire/livewire')
            ->and($record['exceptions'][0]['recorded_on'])->toBe(gmdate('Y-m-d'))
            ->and($record['exceptions'][0]['days'])->toBe(7);
    });

    it('accepts the same Composer security target as --advisory flags', function () {
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'livewire/livewire', '4.9.9', '--advisory', 'PKSA-xhv3-nr94-h8db', '--advisory', 'PKSA-bgw4-5zmg-2njg'], [
            'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()], ['data' => ['advisories' => []]]],
        ]);
        expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    });

    it('refuses an unobserved advisory before any manifest or policy write', function () {
        $original = file_get_contents($this->repo.'/vet.json');
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-wrong'], [
            'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
        ]);
        expect($result->getExitCode())->toBe(64)
            ->and(file_get_contents($this->repo.'/vet.json'))->toBe($original)
            ->and(commandLog($this->directory))->not->toContain('require');
    });

    it('fails when the selected package still has an advisory', function () {
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'], [
            'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
        ]);
        expect($result->getExitCode())->toBe(1)->and($result->getErrorOutput())->toContain('still has security advisories');
    });

    it('rejects injected, missing, and floating targets before calling a manager', function (array $args) {
        $result = runMaintainer($this->directory, $this->repo, $args);
        expect($result->getExitCode())->toBe(64)->and(is_file($this->directory.'/commands'))->toBeFalse();
    })->with([
        [['package', 'composer', 'livewire/livewire other/pkg', '5.0.0', 'major']],
        [['package', 'composer', '--help', '5.0.0', 'major']],
        [['package', 'composer', 'missing/pkg', '5.0.0', 'major']],
        [['package', 'js', 'react', 'latest', 'major']],
        [['package', 'js', 'react', '20.0.0', 'major', 'extra']],
        [['package', 'composer', 'livewire/livewire', '4.9.9', 'security']],
    ]);

    it('installs an npm 11.12 security fix with one age bypass and leaves the pinned manager', function () {
        file_put_contents($this->repo.'/.npmrc', "min-release-age=7\n");
        $before = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        expect($before['packageManager'])->toBe('npm@11.12.0');
        $result = runMaintainer($this->directory, $this->repo, ['package', 'js', '@scope/tool', '1.2.3', 'security', '123'], [
            'vp pm audit --json' => [
                ['code' => 1, 'data' => ['vulnerabilities' => ['@scope/tool' => ['via' => [['source' => 123, 'url' => 'https://github.com/advisories/GHSA-test']]]]]],
                ['data' => ['vulnerabilities' => []]],
            ],
        ], ['DEPENDENCY_TEST_NPM_FRESH' => '1']);
        $after = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and(commandLog($this->directory))->toContain('vp|add @scope/tool@1.2.3 --save-dev --save-exact -- --min-release-age=0|')
            ->and(file_get_contents($this->repo.'/.npmrc'))->toBe("min-release-age=7\n")
            ->and($after['packageManager'])->toBe('npm@11.12.0')
            ->and($after['devDependencies']['@scope/tool'])->toBe('1.2.3')
            ->and($result->getErrorOutput())->not->toContain('ETARGET')
            ->and(is_file($this->repo.'/maintainer-exceptions.json'))->toBeFalse();
    });

    it('does not duplicate a Vet exclusion or its dated record', function () {
        $fixture = ['composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()], ['data' => ['advisories' => []]]]];
        $args = ['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'];
        expect(runMaintainer($this->directory, $this->repo, $args, $fixture)->getExitCode())->toBe(0);
        expect(runMaintainer($this->directory, $this->repo, $args, $fixture)->getExitCode())->toBe(0);
        expect(json_decode((string) file_get_contents($this->repo.'/vet.json'), true)['minimum-release-age-exclude'])->toBe(['existing/pkg', 'livewire/livewire']);
        expect(json_decode((string) file_get_contents($this->repo.'/maintainer-exceptions.json'), true)['exceptions'])->toHaveCount(1);
    });

    it('stops before the targeted update when the range bump fails', function () {
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'dev/tool', '2.0.0', 'major'], [
            'composer require dev/tool:^2.0.0 --no-update --no-interaction --dev' => [['code' => 17, 'raw' => 'cannot change manifest']],
        ]);
        expect($result->getExitCode())->toBe(17)->and(commandLog($this->directory))->not->toContain('composer|update');
    });

    it('refuses aliased and ambiguous packages before manager calls', function () {
        file_put_contents($this->repo.'/package.json', json_encode([
            'dependencies' => ['vite' => 'npm:some-alias@1.0.0', 'vite-plus' => '^0.1.0'],
            'devDependencies' => ['react' => '^19'],
            'optionalDependencies' => ['react' => '^19'],
            'packageManager' => 'npm@11.12.0',
        ]));
        foreach (['vite', 'react'] as $name) {
            expect(runMaintainer($this->directory, $this->repo, ['package', 'js', $name, '2.0.0', 'major'])->getExitCode())->toBe(64);
        }
        expect(is_file($this->directory.'/commands'))->toBeFalse();
    });

    it('does not invent a Composer age policy or exclusion when the target is already fixed', function () {
        unlink($this->repo.'/vet.json');
        $manifest = json_decode((string) file_get_contents($this->repo.'/composer.json'), true);
        $manifest['require']['livewire/livewire'] = '4.9.9';
        file_put_contents($this->repo.'/composer.json', json_encode($manifest));
        $result = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'livewire/livewire', '4.9.9', 'security', 'PKSA-xhv3-nr94-h8db'], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
        ]);
        expect($result->getExitCode())->toBe(0)
            ->and(is_file($this->repo.'/vet.json'))->toBeFalse()
            ->and(is_file($this->repo.'/maintainer-exceptions.json'))->toBeFalse();
    });

    it('reports a dated exception once the release-age window has passed', function () {
        file_put_contents($this->repo.'/maintainer-exceptions.json', json_encode([
            'schema' => 1,
            'exceptions' => [[
                'path' => '.',
                'manager' => 'composer',
                'package' => 'existing/pkg',
                'recorded_on' => '2026-10-01',
                'days' => 7,
            ]],
        ]));
        $before = file_get_contents($this->repo.'/vet.json');
        $result = runMaintainer($this->directory, $this->repo, ['detect'], emptyDetect());
        expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
        $payload = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($payload['candidates'])->toBe([])
            ->and($payload['stale_exceptions'])->toBe([[
                'path' => '.',
                'manager' => 'composer',
                'package' => 'existing/pkg',
                'recorded_on' => '2026-10-01',
                'remove_on' => '2026-10-08',
            ]])
            ->and(file_get_contents($this->repo.'/vet.json'))->toBe($before);
    });
});
