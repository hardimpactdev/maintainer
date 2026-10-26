<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

it('rejects a side name that collides with a manager', function () {
    file_put_contents($this->repo.'/maintainer.json', json_encode([
        'sides' => [['name' => 'composer', 'dir' => '.', 'managers' => ['composer']]],
    ]));
    $result = runMaintainer($this->directory, $this->repo, ['detect']);
    expect($result->getExitCode())->toBe(70)->and($result->getErrorOutput())->toContain('side name is invalid');
});

it('runs configured checks and reports when none are set', function () {
    $plain = runMaintainer($this->directory, $this->repo, ['check']);
    expect($plain->getExitCode())->toBe(0)->and($plain->getOutput())->toBe("No checks configured.\n");

    file_put_contents($this->repo.'/maintainer.json', json_encode([
        'checks' => ['php -r "exit(3);"'],
        'sides' => [['name' => 'web', 'dir' => '.', 'managers' => ['composer']]],
    ]));
    $failed = runMaintainer($this->directory, $this->repo, ['check'], [], [
        'PATH' => $this->directory.'/bin:/usr/bin:/bin',
    ]);
    expect($failed->getExitCode())->toBe(3);
});
