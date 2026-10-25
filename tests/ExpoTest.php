<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
    file_put_contents($this->repo.'/maintainer.json', json_encode([
        'sides' => [
            ['name' => 'web', 'dir' => '.', 'managers' => ['composer', 'js']],
            ['name' => 'mobile', 'dir' => 'mobile', 'managers' => ['js']],
        ],
    ]));
    mkdir($this->repo.'/mobile/node_modules/.bin', 0755, true);
    file_put_contents($this->repo.'/mobile/package.json', json_encode([
        'dependencies' => [
            'expo' => '57.0.28',
            'react' => '19.0.0',
            'react-native' => '0.86.3',
            'expo-camera' => '57.0.6',
            'lodash' => '^4.17.21',
        ],
        'packageManager' => 'npm@11.12.0',
    ]));
    file_put_contents($this->repo.'/mobile/.npmrc', "min-release-age=7\n");
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

function bothSides(): array
{
    return [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => [['name' => 'other/pkg', 'version' => '1.0.0', 'latest' => '2.0.0']]]]],
        'vp outdated --format json' => [['code' => 1, 'data' => ['react' => ['current' => '19.0.0', 'latest' => '20.0.0'], 'unchanged' => ['current' => '1.0.0', 'latest' => '1.1.0']]]],
        'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
        'vp pm audit --json' => [['code' => 1, 'data' => ['vulnerabilities' => ['react' => ['via' => [['source' => 123, 'url' => 'https://github.com/advisories/GHSA-test', 'title' => 'test', 'range' => '<20']]]]]]],
        'npm outdated --json' => [['code' => 1, 'data' => [
            'expo' => ['current' => '57.0.28', 'latest' => '58.0.0'],
            'react-native' => ['current' => '0.86.3', 'latest' => '0.87.0'],
            'lodash' => ['current' => '4.17.21', 'latest' => '5.0.0'],
            'expo-camera' => ['current' => '57.0.6', 'latest' => '57.0.9'],
        ]]],
        'npm audit --json' => [['code' => 1, 'data' => ['vulnerabilities' => [
            'expo-camera' => ['via' => [['source' => 999, 'url' => 'https://github.com/advisories/GHSA-mobile', 'title' => 'camera', 'severity' => 'moderate']]],
            'react-native' => ['via' => [['source' => 1000, 'url' => 'https://github.com/advisories/GHSA-rn', 'title' => 'rn']]],
        ]]]],
    ];
}

it('updates web and then mobile, and stops before later commands', function () {
    $result = runMaintainer($this->directory, $this->repo, ['routine'], [], ['DEPENDENCY_TEST_VP_EXIT' => '9']);
    expect($result->getExitCode())->toBe(9);
    expect(commandLog($this->directory))->toBe(
        'composer|update --no-interaction|'.$this->repo."\n".'vp|update|'.$this->repo."\n",
    );
});

it('fails before updates when npm is not on PATH', function () {
    unlink($this->directory.'/bin/npm');
    $result = runMaintainer($this->directory, $this->repo, ['routine']);
    expect($result->getExitCode())->toBe(127)
        ->and($result->getErrorOutput())->toContain('Missing executable npm on PATH.')
        ->and(is_file($this->directory.'/commands'))->toBeFalse();
});

it('detects both sides, folds an Expo SDK bump, and keeps real advisory ids', function () {
    $result = runMaintainer($this->directory, $this->repo, ['detect'], bothSides());
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    $keys = array_map(fn (array $row) => $row['side'].':'.$row['package'].':'.implode('+', $row['reasons']), $rows);
    expect($keys)->toBe([
        'mobile:expo:major',
        'mobile:expo-camera:security',
        'mobile:lodash:major',
        'mobile:react-native:security',
        'web:livewire/livewire:security',
        'web:other/pkg:major',
        'web:react:major+security',
    ]);
    expect($rows[0]['align'])->toBe('expo-install-fix')
        ->and($rows[0]['current'])->toBe('57.0.28')
        ->and($rows[0]['latest'])->toBe('58.0.0')
        ->and($rows[1]['advisories'][0]['id'])->toBe('999')
        ->and($rows[3]['advisories'][0]['id'])->toBe('1000')
        ->and($rows[6]['advisories'][0]['id'])->toBe('123');
    expect(commandLog($this->directory))->toContain('npm|outdated --json|'.$this->repo.'/mobile', 'npm|audit --json|'.$this->repo.'/mobile');
});

it('does not invent an Expo SDK bump when only a coupled package moved', function () {
    $fixture = bothSides();
    $fixture['composer outdated --locked --major-only --format=json'] = [['data' => ['locked' => []]]];
    $fixture['composer audit --locked --format=json'] = [['data' => ['advisories' => []]]];
    $fixture['vp outdated --format json'] = [['data' => []]];
    $fixture['vp pm audit --json'] = [['data' => ['vulnerabilities' => []]]];
    $fixture['npm outdated --json'] = [['code' => 1, 'data' => ['react-native' => ['current' => '0.86.3', 'latest' => '0.87.0']]]];
    $fixture['npm audit --json'] = [['data' => ['vulnerabilities' => []]]];
    $result = runMaintainer($this->directory, $this->repo, ['detect'], $fixture);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput())
        ->and(json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'])->toBe([]);
});

it('installs an Expo SDK with expo install and then expo install --fix', function () {
    $before = json_decode((string) file_get_contents($this->repo.'/mobile/package.json'), true);
    $result = runMaintainer($this->directory, $this->repo, ['package', 'mobile', 'js', 'expo', '58.0.0', 'major']);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $log = commandLog($this->directory);
    expect($log)->toContain('npx|expo install expo@58.0.0|'.$this->repo.'/mobile')
        ->and($log)->toContain('npx|expo install --fix|'.$this->repo.'/mobile')
        ->and($log)->not->toContain('min-release-age')
        ->and($log)->not->toContain('npm|install');
    $manifest = json_decode((string) file_get_contents($this->repo.'/mobile/package.json'), true);
    expect($manifest['dependencies']['expo'])->toBe('58.0.0')
        ->and($manifest['dependencies']['react-native'])->toBe($before['dependencies']['react-native']);
});

it('refuses an independent coupled major', function (string $package) {
    $result = runMaintainer($this->directory, $this->repo, ['package', 'mobile', 'js', $package, '1.2.3', 'major']);
    expect($result->getExitCode())->toBe(64)
        ->and($result->getErrorOutput())->toContain('Expo SDK and React Native are coupled')
        ->and(is_file($this->directory.'/commands'))->toBeFalse();
})->with(['react-native', 'react', 'expo-camera', '@expo/ui']);

it('pins one mobile advisory with npm and bypasses only that install', function () {
    $result = runMaintainer($this->directory, $this->repo, ['package', 'mobile', 'js', 'lodash', '4.17.23', 'security', '555'], [
        'npm audit --json' => [
            ['code' => 1, 'data' => ['vulnerabilities' => ['lodash' => ['via' => [['source' => 555, 'url' => 'https://github.com/advisories/GHSA-lodash']]]]]],
            ['data' => ['vulnerabilities' => []]],
        ],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput())
        ->and(commandLog($this->directory))->toContain('npm|install lodash@4.17.23 --save-prod --save-exact --min-release-age=0|'.$this->repo.'/mobile');
    expect(file_get_contents($this->repo.'/mobile/.npmrc'))->toBe("min-release-age=7\n");
});

it('installs an Expo security fix through expo and aligns coupled packages', function () {
    $result = runMaintainer($this->directory, $this->repo, ['package', 'mobile', 'js', 'expo', '57.0.30', 'security', '777'], [
        'npm audit --json' => [
            ['code' => 1, 'data' => ['vulnerabilities' => ['expo' => ['via' => [['source' => 777, 'url' => 'https://github.com/advisories/GHSA-expo']]]]]],
            ['data' => ['vulnerabilities' => []]],
        ],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $log = commandLog($this->directory);
    expect($log)->toContain('npx|expo install expo@57.0.30|'.$this->repo.'/mobile|min-release-age=0')
        ->and($log)->toContain('npx|expo install --fix|'.$this->repo.'/mobile|min-release-age=0')
        ->and($log)->not->toContain('npm|install');
});

it('requires a side when several sides contain the same manager', function () {
    $result = runMaintainer($this->directory, $this->repo, ['package', 'js', 'lodash', '5.0.0', 'major']);
    expect($result->getExitCode())->toBe(64)
        ->and($result->getErrorOutput())->toContain('Several sides have js')
        ->and(is_file($this->directory.'/commands'))->toBeFalse();
});
