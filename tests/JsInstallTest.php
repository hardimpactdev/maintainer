<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

it('installs a fresh Vite+ side with npm ci before detect and leaves the lockfile unchanged', function () {
    expect(is_dir($this->repo.'/node_modules'))->toBeTrue();
    (new Process(['rm', '-rf', '--', $this->repo.'/node_modules']))->mustRun();
    expect(is_dir($this->repo.'/node_modules'))->toBeFalse()
        ->and(is_file($this->repo.'/node_modules/.bin/vp'))->toBeFalse();
    $lock = (string) file_get_contents($this->repo.'/package-lock.json');
    $manifest = (string) file_get_contents($this->repo.'/package.json');

    $result = runMaintainer($this->directory, $this->repo, ['detect'], freshDetect());

    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $log = commandLog($this->directory);
    expect($log)->toContain('npm|ci --ignore-scripts|'.$this->repo)
        ->and(strpos($log, 'npm|ci --ignore-scripts|'))->toBeLessThan(strpos($log, 'vp|outdated --format json|'))
        ->and($log)->not->toContain('npm|install')
        ->and(is_executable($this->repo.'/node_modules/.bin/vp'))->toBeTrue()
        ->and(file_get_contents($this->repo.'/package-lock.json'))->toBe($lock)
        ->and(file_get_contents($this->repo.'/package.json'))->toBe($manifest);
    $payload = json_decode($result->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['schema'])->toBe(1)
        ->and($result->getOutput())->not->toContain('added 12 packages')
        ->and($result->getErrorOutput())->toContain('added 12 packages');
});

it('installs a fresh Bun Vite+ side with a frozen install and leaves bun.lock unchanged', function () {
    file_put_contents($this->repo.'/package.json', json_encode([
        'dependencies' => ['vite-plus' => '^0.1.0', 'react' => '^19.0'],
        'packageManager' => 'bun@1.3.14',
    ]));
    unlink($this->repo.'/package-lock.json');
    file_put_contents($this->repo.'/bun.lock', <<<'JSON'
{
  "lockfileVersion": 1,
  "packages": {
    "vite-plus": ["vite-plus@0.1.0", "", {}, "sha"],
    "react": ["react@19.0.0", "", {}, "sha"],
  },
}
JSON);
    (new Process(['rm', '-rf', '--', $this->repo.'/node_modules']))->mustRun();
    $lock = (string) file_get_contents($this->repo.'/bun.lock');

    $result = runMaintainer($this->directory, $this->repo, ['detect'], freshDetect());

    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $log = commandLog($this->directory);
    expect($log)->toContain('bun|install --frozen-lockfile --ignore-scripts|'.$this->repo)
        ->and(strpos($log, 'bun|install --frozen-lockfile --ignore-scripts|'))->toBeLessThan(strpos($log, 'vp|outdated --format json|'))
        ->and($log)->not->toContain('npm|ci')
        ->and(is_executable($this->repo.'/node_modules/.bin/vp'))->toBeTrue()
        ->and(file_get_contents($this->repo.'/bun.lock'))->toBe($lock);
    $payload = json_decode($result->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['schema'])->toBe(1)
        ->and($result->getOutput())->not->toContain('added 12 packages')
        ->and($result->getErrorOutput())->toContain('added 12 packages');
});

it('stops when the frozen install fails and does not run Vite+', function () {
    (new Process(['rm', '-rf', '--', $this->repo.'/node_modules']))->mustRun();
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [], [
        'DEPENDENCY_TEST_NPM_EXIT' => '17',
    ]);
    expect($result->getExitCode())->toBe(17)
        ->and($result->getErrorOutput())->toContain('Frozen npm install failed for side root.')
        ->and(commandLog($this->directory))->toBe('npm|ci --ignore-scripts|'.$this->repo."\n")
        ->and(is_file($this->repo.'/node_modules/.bin/vp'))->toBeFalse();
});

it('does not install again when node_modules exists without an executable vp', function () {
    unlink($this->repo.'/node_modules/.bin/vp');
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(127)
        ->and($result->getErrorOutput())->toContain('Missing executable node_modules/.bin/vp; install the repository dependencies first.')
        ->and(commandLog($this->directory))->not->toContain('npm|ci')
        ->and(is_file($this->directory.'/commands'))->toBeFalse();
});

it('sends wrapper bootstrap output to stderr and keeps detect JSON on stdout', function () {
    mkdir($this->repo.'/scripts');
    copy(packageRoot().'/resources/dependencies', $this->repo.'/scripts/dependencies');
    chmod($this->repo.'/scripts/dependencies', 0755);
    mkdir($this->directory.'/wrap-bin');
    file_put_contents($this->directory.'/wrap-bin/composer', <<<'SH'
#!/bin/sh
echo "Installing dependencies from lock file"
mkdir -p vendor/bin
cat > vendor/bin/maintainer << 'EOF'
#!/bin/sh
printf '%s\n' '{"schema":1,"candidates":[]}'
EOF
chmod +x vendor/bin/maintainer
SH);
    chmod($this->directory.'/wrap-bin/composer', 0755);
    $result = new Process([$this->repo.'/scripts/dependencies', 'detect'], $this->repo, [
        'PATH' => $this->directory.'/wrap-bin:'.getenv('PATH'),
    ]);
    $result->run();
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $payload = json_decode($result->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toBe(['schema' => 1, 'candidates' => []])
        ->and($result->getOutput())->not->toContain('Installing dependencies')
        ->and($result->getErrorOutput())->toContain('Installing dependencies from lock file');
});

/** @return array<string, list<array<string, mixed>>> */
function freshDetect(): array
{
    return [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => []]]],
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
        'vp outdated --format json' => [['data' => []]],
        'vp pm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ];
}
