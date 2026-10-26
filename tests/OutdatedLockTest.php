<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

it('reads current from the lockfile when npm outdated omits it', function (string $project, string $expoCurrent) {
    $outdated = fixtureJson($project.'-mobile-outdated.json');
    expect($outdated)->not->toBeEmpty();
    foreach ($outdated as $item) {
        expect($item)->not->toHaveKey('current')
            ->and(array_keys($item))->toEqualCanonicalizing(['wanted', 'latest', 'dependent']);
    }
    writeMobileSide($this->repo, array_keys($outdated), fixtureJson($project.'-mobile-locked.json'));
    $manifest = json_decode((string) file_get_contents($this->repo.'/mobile/package.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest)->not->toHaveKey('packageManager')
        ->and($manifest)->not->toHaveKey('devEngines')
        ->and(is_dir($this->repo.'/mobile/node_modules'))->toBeFalse();

    $result = runMaintainer($this->directory, $this->repo, ['detect', 'mobile'], [
        'npm outdated --json' => [['code' => 1, 'data' => $outdated]],
        'npm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ]);

    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['package'])->toBe('expo')
        ->and($rows[0]['current'])->toBe($expoCurrent)
        ->and($rows[0]['latest'])->toBe('58.0.14')
        ->and($rows[0]['align'])->toBe('expo-install-fix')
        ->and($rows[0]['path'])->toBe('mobile');
    expect(commandLog($this->directory))->toContain('npm|outdated --json|'.$this->repo.'/mobile')
        ->and(commandLog($this->directory))->not->toContain('npm|install')
        ->and(commandLog($this->directory))->not->toContain('npm|ci')
        ->and(is_dir($this->repo.'/mobile/node_modules'))->toBeFalse();
})->with([
    'hauzer mobile' => ['hauzer', '57.0.28'],
    'mealou mobile' => ['mealou', '57.0.27'],
]);

it('uses the locked version rather than wanted when npm omits current', function () {
    $outdated = fixtureJson('hauzer-mobile-outdated.json');
    expect($outdated['expo-auth-session']['wanted'])->toBe('57.0.16')
        ->and($outdated['expo-auth-session'])->not->toHaveKey('current');
    writeMobileSide($this->repo, ['expo-auth-session'], ['expo-auth-session' => '57.0.15']);

    $result = runMaintainer($this->directory, $this->repo, ['detect', 'mobile'], [
        'npm outdated --json' => [['code' => 1, 'data' => $outdated]],
        'npm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ]);

    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['expo-auth-session'])
        ->and($rows[0]['current'])->toBe('57.0.15')
        ->and($rows[0]['latest'])->toBe('58.0.13');
    expect(commandLog($this->directory))->not->toContain('npm|install');
});

it('fails when a direct package has no current version in npm outdated or the lockfile', function () {
    writeMobileSide($this->repo, ['lodash'], []);
    $result = runMaintainer($this->directory, $this->repo, ['detect', 'mobile'], [
        'npm outdated --json' => [['code' => 1, 'data' => [
            'lodash' => ['wanted' => '4.17.21', 'latest' => '5.0.0', 'dependent' => 'mobile'],
        ]]],
        'npm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ]);
    expect($result->getExitCode())->toBe(70)
        ->and($result->getErrorOutput())->toContain('no current version: lodash')
        ->and(commandLog($this->directory))->not->toContain('npm|install');
});

it('keeps a reported current version when the lockfile disagrees', function () {
    writeMobileSide($this->repo, ['react'], ['react' => '9.9.9']);
    $result = runMaintainer($this->directory, $this->repo, ['detect', 'mobile'], [
        'npm outdated --json' => [['code' => 1, 'data' => [
            'react' => ['current' => '19.0.0', 'latest' => '20.0.0'],
        ]]],
        'npm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect($rows[0]['current'])->toBe('19.0.0')
        ->and($rows[0]['latest'])->toBe('20.0.0');
});

it('reads a missing Bun current version from bun.lock', function () {
    unlink($this->repo.'/composer.json');
    unlink($this->repo.'/package-lock.json');
    file_put_contents($this->repo.'/package.json', json_encode([
        'dependencies' => ['left-pad' => '^1.0.0'],
        'packageManager' => 'bun@1.3.14',
    ]));
    file_put_contents($this->repo.'/bun.lock', <<<'JSON'
{
  "lockfileVersion": 1,
  "packages": {
    "left-pad": ["left-pad@1.2.3", "", {}, "sha"],
  },
}
JSON);
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        'bun outdated' => [['code' => 1, 'data' => [
            'left-pad' => ['wanted' => '1.9.0', 'latest' => '2.0.0', 'dependent' => 'app'],
        ]]],
        'bun pm audit --json' => [['data' => []]],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['package'])->toBe('left-pad')
        ->and($rows[0]['current'])->toBe('1.2.3')
        ->and($rows[0]['latest'])->toBe('2.0.0');
    expect(commandLog($this->directory))->toContain('bun|outdated|')
        ->and(commandLog($this->directory))->not->toContain('bun|install')
        ->and(commandLog($this->directory))->not->toContain('npm|install');
});

/** @return array<string, mixed> */
function fixtureJson(string $name): array
{
    $data = json_decode((string) file_get_contents(packageRoot().'/tests/Fixtures/dependencies/'.$name), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($data)) {
        throw new RuntimeException('Bad fixture '.$name);
    }

    return $data;
}

/** @param list<string> $names @param array<string, string> $versions */
function writeMobileSide(string $repo, array $names, array $versions): void
{
    file_put_contents($repo.'/maintainer.json', json_encode([
        'paths' => ['mobile'],
    ]));
    mkdir($repo.'/mobile');
    $dependencies = [];
    foreach ($names as $name) {
        $dependencies[$name] = '*';
    }
    file_put_contents($repo.'/mobile/package.json', json_encode([
        'name' => 'mobile',
        'private' => true,
        'dependencies' => $dependencies,
    ]));
    $packages = ['' => ['name' => 'mobile']];
    foreach ($versions as $name => $version) {
        $packages['node_modules/'.$name] = ['version' => $version];
    }
    file_put_contents($repo.'/mobile/package-lock.json', json_encode([
        'name' => 'mobile',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => $packages,
    ]));
}
