<?php

declare(strict_types=1);

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
    file_put_contents($this->repo.'/package.json', json_encode([
        'dependencies' => [
            '@nckrtl/launch-ui' => '0.0.x',
            'shadcn' => '^4.21.0',
            'react' => '^19.0',
        ],
        'devDependencies' => [
            'vite' => 'npm:@voidzero-dev/vite-plus-core@0.2.8',
            'vite-plus' => '^0.2.9',
        ],
        'packageManager' => 'bun@1.3.14',
    ]));
    file_put_contents($this->repo.'/bun.lock', <<<'JSON'
{
  "lockfileVersion": 1,
  "packages": {
    "@nckrtl/launch-ui": ["@nckrtl/launch-ui@0.0.31", "", {"dependencies": {"@storybook/addon-mcp": "1.0.0"}, "peerDependencies": {"vite-plus": "^0.2.0"}}, "sha"],
    "@storybook/addon-mcp": ["@storybook/addon-mcp@1.0.0", "", {"peerDependencies": {"storybook": "^10.0.0"}}, "sha"],
    "storybook": ["storybook@10.5.6", "", {}, "sha"],
    "shadcn": ["shadcn@4.21.0", "", {"dependencies": {"zod": "^3.0.0"}}, "sha"],
    "zod": ["zod@3.25.76", "", {}, "sha"],
    "vite-plus": ["vite-plus@0.2.9", "", {}, "sha"],
    "vite": ["@voidzero-dev/vite-plus-core@0.2.8", "", {}, "sha"],
  },
}
JSON);
    unlink($this->repo.'/package-lock.json');
    $this->table = implode("\n", [
        'bun outdated v1.3.14',
        '|----------------------------------------------------------------|',
        '| Package                            | Current | Update | Latest |',
        '|------------------------------------|---------|--------|--------|',
        '| @nckrtl/launch-ui                  | 0.0.31  | 0.0.33 | 0.1.0  |',
        '|------------------------------------|---------|--------|--------|',
        '| shadcn                             | 4.21.0  | 4.22.1 | 4.22.1 |',
        '|------------------------------------|---------|--------|--------|',
        '| @voidzero-dev/vite-plus-core (dev) | 0.2.8   | 0.2.8  | 1.4.0  |',
        '|------------------------------------|---------|--------|--------|',
        '| vite-plus (dev)                    | 0.2.9   | 0.2.10 | 0.2.10 |',
        '|----------------------------------------------------------------|',
    ]);
    $this->audit = [
        'zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod', 'severity' => 'high', 'vulnerable_versions' => '<4.6.6']],
        'storybook' => [['id' => 999, 'url' => 'https://github.com/advisories/GHSA-story', 'title' => 'Storybook', 'severity' => 'moderate', 'vulnerable_versions' => '<10.5.7']],
        'vite-plus' => [['id' => 111, 'url' => 'https://github.com/advisories/GHSA-vp', 'title' => 'vite-plus', 'severity' => 'low', 'vulnerable_versions' => '<0.3.0']],
    ];
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

function bunDetect(string $table, array $audit): array
{
    return [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => []]]],
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
        'vp outdated --format json' => [['raw' => $table]],
        'vp pm audit --json' => [['code' => 1, 'data' => $audit]],
    ];
}

it('parses the Bun table, skips the Vite alias target, and walks peers in bun.lock', function () {
    $result = runMaintainer($this->directory, $this->repo, ['detect'], bunDetect($this->table, $this->audit));
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['@nckrtl/launch-ui', 'shadcn', 'vite-plus'])
        ->and(json_encode($rows))->not->toContain('@voidzero-dev/vite-plus-core');
    expect($rows[0]['reasons'])->toBe(['major', 'security'])
        ->and(array_column($rows[0]['advisories'], 'id'))->toBe(['999'])
        ->and($rows[0]['current'])->toBe('0.0.31')
        ->and($rows[0]['latest'])->toBe('0.1.0');
    expect($rows[1]['advisories'][0]['id'])->toBe('1243066');
    expect(array_column($rows[2]['advisories'], 'id'))->toBe(['111']);
    expect(commandLog($this->directory))->toContain('vp|outdated --format json|');
});

it('reads the real Bun outdated table and strips the release-age marker', function () {
    file_put_contents($this->repo.'/package.json', json_encode([
        'dependencies' => ['@laravel/passkeys' => '^0.2.0', 'react' => '^19.2.0'],
        'devDependencies' => ['vite-plus' => '^0.3.3'],
        'packageManager' => 'bun@1.3.14',
    ]));
    unlink($this->repo.'/composer.json');
    $table = (string) file_get_contents(packageRoot().'/tests/Fixtures/dependencies/bun-outdated.txt');
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        'vp outdated --format json' => [['raw' => $table]],
        'vp pm audit --json' => [['data' => []]],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['@laravel/passkeys', 'vite-plus'])
        ->and($rows[0]['latest'])->toBe('0.4.0')
        ->and($rows[1]['current'])->toBe('0.3.3')
        ->and($rows[1]['latest'])->toBe('1.3.0')
        ->and(json_encode($rows))->not->toContain('@voidzero-dev/vite-plus-core')
        ->and(json_encode($rows))->not->toContain('*');
});

it('attaches a real Bun audit document through the lockfile', function () {
    file_put_contents($this->repo.'/package.json', json_encode([
        'dependencies' => ['shadcn' => '^4.1.0'],
        'devDependencies' => ['vite-plus' => '^0.3.3'],
        'packageManager' => 'bun@1.3.14',
    ]));
    file_put_contents($this->repo.'/bun.lock', <<<'JSON'
{
  "packages": {
    "shadcn": ["shadcn@4.1.0", "", {"dependencies": {"zod": "^4"}}, "sha"],
    "zod": ["zod@4.0.0", "", {}, "sha"],
    "vite-plus": ["vite-plus@0.3.3", "", {"dependencies": {"@vitest/browser": "4.0.0"}}, "sha"],
    "@vitest/browser": ["@vitest/browser@4.0.0", "", {"dependencies": {"@vitest/mocker": "4.0.0"}}, "sha"],
    "@vitest/mocker": ["@vitest/mocker@4.0.0", "", {}, "sha"],
  },
}
JSON);
    unlink($this->repo.'/composer.json');
    $audit = json_decode((string) file_get_contents(packageRoot().'/tests/Fixtures/dependencies/bun-audit.json'), true, flags: JSON_THROW_ON_ERROR);
    $result = runMaintainer($this->directory, $this->repo, ['detect'], [
        'vp outdated --format json' => [['raw' => "bun outdated v1.3.14\n"]],
        'vp pm audit --json' => [['code' => 1, 'data' => $audit]],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput());
    $rows = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR)['candidates'];
    expect(array_column($rows, 'package'))->toBe(['shadcn', 'vite-plus']);
    expect(array_column($rows[0]['advisories'], 'id'))->toBe(['1243066']);
    expect(array_column($rows[1]['advisories'], 'id'))->toBe(['1242723', '111']);
});

it('fails when a Bun advisory is not reachable from a direct dependency', function () {
    $audit = $this->audit;
    $audit['ghost'] = [['id' => 5, 'url' => 'https://example.test/ghost', 'title' => 'ghost']];
    $result = runMaintainer($this->directory, $this->repo, ['detect'], bunDetect($this->table, $audit));
    expect($result->getExitCode())->toBe(70)
        ->and($result->getOutput())->toBe('')
        ->and($result->getErrorOutput())->toContain('Cannot attach bun advisory to a direct dependency: ghost');
});

it('rejects Bun outdated output that is not the table', function () {
    $fixture = bunDetect($this->table, $this->audit);
    $fixture['vp outdated --format json'] = [['raw' => 'not a table']];
    $result = runMaintainer($this->directory, $this->repo, ['detect'], $fixture);
    expect($result->getExitCode())->toBe(70)->and($result->getErrorOutput())->toContain('Unsupported Bun outdated output');
});

it('pins a Bun security fix through vp and records only that package in bunfig', function () {
    file_put_contents($this->repo.'/bunfig.toml', "[install]\nminimumReleaseAge = 604800\n");
    $result = runMaintainer($this->directory, $this->repo, ['package', 'js', 'shadcn', '4.21.6', 'security', '1243066'], [
        'vp pm audit --json' => [
            ['code' => 1, 'data' => ['zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod', 'severity' => 'high', 'vulnerable_versions' => '<4.6.6']]]],
            ['data' => []],
        ],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput())
        ->and(commandLog($this->directory))->toContain('vp|add shadcn@4.21.6 --save-prod --save-exact|')
        ->and(commandLog($this->directory))->not->toContain('min-release-age');
    expect(file_get_contents($this->repo.'/bunfig.toml'))->toContain('minimumReleaseAgeExcludes = ["shadcn"]');
    $again = runMaintainer($this->directory, $this->repo, ['package', 'js', 'shadcn', '4.21.6', 'security', '1243066'], [
        'vp pm audit --json' => [
            ['code' => 1, 'data' => ['zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod']]]],
            ['data' => []],
        ],
    ]);
    expect($again->getExitCode())->toBe(0);
    expect(substr_count((string) file_get_contents($this->repo.'/bunfig.toml'), 'shadcn'))->toBe(1);
});

it('does not invent a Bun age policy', function () {
    $result = runMaintainer($this->directory, $this->repo, ['package', 'js', 'shadcn', '4.21.6', 'security', '1243066'], [
        'vp pm audit --json' => [
            ['code' => 1, 'data' => ['zod' => [['id' => 1243066, 'url' => 'https://github.com/advisories/GHSA-mrhx-c7xc-m3w5', 'title' => 'Zod']]]],
            ['data' => []],
        ],
    ]);
    expect($result->getExitCode())->toBe(0, $result->getErrorOutput())
        ->and(is_file($this->repo.'/bunfig.toml'))->toBeFalse();
});

it('refuses to rewrite the vite alias before calling a manager', function () {
    $result = runMaintainer($this->directory, $this->repo, ['package', 'js', 'vite', '1.4.0', 'major']);
    expect($result->getExitCode())->toBe(64)->and(is_file($this->directory.'/commands'))->toBeFalse();
});
