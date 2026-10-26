<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
    $this->audit = json_decode((string) file_get_contents(packageRoot().'/tests/Fixtures/dependencies/npm-audit-shadcn.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
    $manifest['dependencies']['shadcn'] = '^4.1.0';
    file_put_contents($this->repo.'/package.json', json_encode($manifest));
    $this->detect = [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => []]]],
        'vp outdated --format json' => [['data' => []]],
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
    ];
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

it('attaches the real Zod advisory to shadcn through the via chain', function () {
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        ...$this->detect,
        'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['shadcn'])
        ->and($rows[0]['advisories'])->toBe([[
            'id' => '1243066',
            'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5',
            'title' => 'Zod can exhaust memory while validating oversized constrained collections',
            'affected' => '<4.6.6',
            'severity' => 'high',
        ]]);
});

it('verifies the real advisory id cleared and refuses a package-name placeholder', function (bool $cleared) {
    $after = $this->audit;
    if ($cleared) {
        unset($after['vulnerabilities']['shadcn'], $after['vulnerabilities']['@shadcn/registry']);
        $after['vulnerabilities']['react'] = ['via' => ['zod'], 'isDirect' => true];
    }
    $result = runMaintainer($this->directory, $this->repo, ['package', 'js', 'shadcn', '4.21.6', 'security', '1243066'], [
        'vp pm audit --json' => [['code' => 1, 'data' => $this->audit], ['code' => 1, 'data' => $after]],
    ]);
    expect($result->getExitCode())->toBe($cleared ? 0 : 1);
    if (! $cleared) {
        expect($result->getErrorOutput())->toContain('The selected package still has security advisories');
    }
})->with([true, false]);

it('refuses a package name used as an advisory id before updating', function () {
    $before = file_get_contents($this->repo.'/package.json');
    $result = runMaintainer($this->directory, $this->repo, ['package', 'js', 'shadcn', '4.21.6', 'security', 'shadcn'], [
        'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
    ]);
    expect($result->getExitCode())->toBe(64)
        ->and(commandLog($this->directory))->not->toContain('vp|add')
        ->and(file_get_contents($this->repo.'/package.json'))->toBe($before);
});

it('keeps one shared advisory on every controlling direct dependency', function () {
    $this->audit['vulnerabilities']['react'] = ['via' => ['zod'], 'isDirect' => true];
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        ...$this->detect,
        'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['react', 'shadcn'])
        ->and(array_column($rows[0]['advisories'], 'id'))->toBe(['1243066'])
        ->and($rows[1]['advisories'])->toBe($rows[0]['advisories']);
});

it('fails closed when a via chain has no resolvable advisory', function (array $via) {
    $this->audit['vulnerabilities']['shadcn']['via'] = $via;
    $this->audit['vulnerabilities']['@shadcn/registry']['via'] = ['shadcn'];
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        ...$this->detect,
        'vp pm audit --json' => [['code' => 1, 'data' => $this->audit]],
    ]);
    expect($result->getExitCode())->toBe(70)->and($result->getOutput())->toBe('');
})->with([[['missing-package']], [['@shadcn/registry']], [[]]]);
