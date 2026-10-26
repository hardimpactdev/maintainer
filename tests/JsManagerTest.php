<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

it('refuses a declared manager when its lockfile is missing or foreign', function (string $packageManager, array $locks, string $error) {
    $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['packageManager'] = $packageManager;
    file_put_contents($this->repo.'/package.json', json_encode($manifest));
    unlink($this->repo.'/package-lock.json');
    foreach ($locks as $lock) {
        file_put_contents($this->repo.'/'.$lock, "{}\n");
    }
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(70)
        ->and($result->getErrorOutput())->toContain($error)
        ->and(is_file($this->directory.'/commands'))->toBeFalse();
})->with([
    'npm with only bun.lock' => [
        'npm@11.12.0',
        ['bun.lock'],
        'package.json declares npm but this side has bun.lock and no package-lock.json.',
    ],
    'bun with only package-lock.json' => [
        'bun@1.3.14',
        ['package-lock.json'],
        'package.json declares bun but this side has package-lock.json and no bun.lock.',
    ],
    'npm with no lockfile' => [
        'npm@11.12.0',
        [],
        'package.json declares npm but this side has no package-lock.json.',
    ],
    'bun with both lockfiles' => [
        'bun@1.3.14',
        ['package-lock.json', 'bun.lock'],
        'package.json declares bun but this side also has package-lock.json.',
    ],
]);
