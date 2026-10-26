<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
    if (isset($this->outside) && is_dir($this->outside)) {
        (new Process(['rm', '-rf', '--', $this->outside]))->mustRun();
    }
});

it('detects an application that lives in a subdirectory', function () {
    $app = $this->repo.'/server/web';
    mkdir($app.'/node_modules/.bin', 0755, true);
    copy($this->repo.'/composer.json', $app.'/composer.json');
    copy($this->repo.'/package.json', $app.'/package.json');
    copy($this->repo.'/package-lock.json', $app.'/package-lock.json');
    copy($this->repo.'/node_modules/.bin/vp', $app.'/node_modules/.bin/vp');
    chmod($app.'/node_modules/.bin/vp', 0755);
    file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => ['server/web']]));

    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => [['name' => 'other/pkg', 'version' => '1.0.0', 'latest' => '2.0.0']]]]],
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
        'vp outdated --format json' => [['data' => []]],
        'vp pm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ]);

    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['path'])->toBe('server/web')
        ->and($rows[0]['package'])->toBe('other/pkg');

    $missing = runMaintainer($this->directory, $this->repo, ['package', 'composer', 'other/pkg', '2.0.0', 'major']);
    expect($missing->getExitCode())->toBe(64)
        ->and($missing->getErrorOutput())->toContain('Unknown path .');

    $updated = runMaintainer($this->directory, $this->repo, ['package', 'server/web', 'composer', 'other/pkg', '2.0.0', 'major']);
    expect($updated->getExitCode())->toBe(0, $updated->getErrorOutput());
    expect(commandLog($this->directory))->toContain('composer|require other/pkg:^2.0.0 --no-update --no-interaction|'.$app);
});

it('rejects paths that leave the repository or repeat', function (array $paths, string $error) {
    file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => $paths]));
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(70)
        ->and($result->getErrorOutput())->toContain($error);
})->with([
    'absolute' => [['/tmp'], 'is absolute'],
    'parent' => [['../outside'], 'escapes the repository'],
    'dot segment' => [['server/./web'], 'escapes the repository'],
    'duplicate' => [['.', '.'], 'repeats path .'],
]);

it('rejects a symlink that resolves outside the repository', function () {
    $this->outside = sys_get_temp_dir().'/maintainer-outside-'.bin2hex(random_bytes(4));
    mkdir($this->outside);
    file_put_contents($this->outside.'/composer.json', '{}');
    symlink($this->outside, $this->repo.'/linked');
    file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => ['linked']]));
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(70)
        ->and($result->getErrorOutput())->toContain('Path linked escapes the repository');
});

it('rejects two paths that resolve to the same directory', function () {
    mkdir($this->repo.'/server/web', 0755, true);
    file_put_contents($this->repo.'/server/web/composer.json', '{}');
    symlink('server/web', $this->repo.'/alias');
    file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => ['server/web', 'alias']]));
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(70)
        ->and($result->getErrorOutput())->toContain('repeats path alias');
});

it('rejects a path with neither Composer nor JavaScript', function () {
    mkdir($this->repo.'/empty');
    file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => ['empty']]));
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(70)
        ->and($result->getErrorOutput())->toContain('Path empty has no composer.json or package.json');
});
