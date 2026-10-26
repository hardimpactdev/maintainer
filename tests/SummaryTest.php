<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function () {
    [$this->directory, $this->repo] = newMaintainerRepo();
    enableGit($this->directory);
});

afterEach(function () {
    removeMaintainerRepo($this->directory);
});

describe('summary', function () {
    it('describes a routine update and skips an unchanged transitive package', function () {
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0', 'symfony/polyfill-php80' => '1.27.0'], ['dev/tool' => '1.0.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0', '@scope/tool' => '1.0.0', 'loose-envify' => '1.0.0']),
        ]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['livewire/livewire' => '4.2.1', 'other/pkg' => '1.0.0', 'symfony/polyfill-php80' => '1.28.0'], ['dev/tool' => '1.0.0']));
        file_put_contents($this->repo.'/package-lock.json', npmLock(['react' => '19.1.0', '@scope/tool' => '1.0.0', 'loose-envify' => '1.0.1']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], emptyAudits());

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update dependencies for '.gmdate('F Y'),
                'Updated 2 packages.',
                'Nothing changes for users of the app.',
                [
                    ['livewire/livewire', '4.2.0', '4.2.1', ''],
                    ['react', '19.0.0', '19.1.0', ''],
                ],
            ))
            ->and($result->getOutput())->not->toContain('polyfill', 'loose-envify', 'Swamp', '{')
            ->and($result->getErrorOutput())->toBe('')
            ->and(commandLog($this->directory))->toContain('composer|audit --locked --format=json|', 'vp|pm audit --json|');
    });

    it('describes one major upgrade', function () {
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.2.3']),
            'package-lock.json' => npmLock(['react' => '19.0.0']),
        ]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '2.0.0']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], emptyAudits(false));

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update other/pkg to 2.0.0',
                'Updated other/pkg from 1.2.3 to 2.0.0.',
                'This includes a major upgrade. Read the upgrade notes before you deploy.',
                [['other/pkg', '1.2.3', '2.0.0', 'major']],
            ));
    });

    it('treats a newer 0.x minor in a bun lock as a major', function () {
        unlink($this->repo.'/package-lock.json');
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['packageManager'] = 'bun@1.2.0';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $base = commitSummary($this->directory, $this->repo, [
            'bun.lock' => bunLock(['@scope/tool' => '0.4.0']),
        ]);
        file_put_contents($this->repo.'/bun.lock', bunLock(['@scope/tool' => '0.5.0']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'vp pm audit --json' => [['data' => ['vulnerabilities' => []]]],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update @scope/tool to 0.5.0',
                'Updated @scope/tool from 0.4.0 to 0.5.0.',
                'This includes a major upgrade. Read the upgrade notes before you deploy.',
                [['@scope/tool', '0.4.0', '0.5.0', 'major']],
            ))
            ->and(commandLog($this->directory))->toContain('vp|pm audit --json|');
    });

    it('links the advisory on a security fix', function () {
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0']),
        ]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['livewire/livewire' => '4.5.0', 'other/pkg' => '1.0.0']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['code' => 1, 'data' => livewireFinding()]],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update livewire/livewire to 4.5.0 (security fix)',
                'Updated livewire/livewire from 4.2.0 to 4.5.0 to fix a security advisory.',
                'Nothing changes for users of the app.',
                [[
                    'livewire/livewire',
                    '4.2.0',
                    '4.5.0',
                    'security fix [PKSA-bgw4-5zmg-2njg](https://packagist.org/security-advisories/PKSA-bgw4-5zmg-2njg), [PKSA-xhv3-nr94-h8db](https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db)',
                ]],
            ))
            ->and($result->getOutput())->not->toContain('{', 'Swamp');
    });

    it('names both paths when the root and a package.json without packageManager change', function () {
        file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => ['.', 'mobile']]));
        mkdir($this->repo.'/mobile');
        file_put_contents($this->repo.'/mobile/package.json', json_encode([
            'name' => 'mobile',
            'private' => true,
            'dependencies' => ['expo' => '^51.0.0'],
        ]));
        $base = commitSummary($this->directory, $this->repo, [
            'maintainer.json' => (string) file_get_contents($this->repo.'/maintainer.json'),
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0']),
            'mobile/package.json' => (string) file_get_contents($this->repo.'/mobile/package.json'),
            'mobile/package-lock.json' => npmLock(['expo' => '51.0.0', 'react-native' => '0.74.0']),
        ]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.1.0']));
        file_put_contents($this->repo.'/mobile/package-lock.json', npmLock(['expo' => '51.0.1', 'react-native' => '0.74.1']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'npm audit --json' => [['data' => ['vulnerabilities' => []]]],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update dependencies for '.gmdate('F Y'),
                'Updated 2 packages in `.` and `mobile`.',
                'Nothing changes for users of the app.',
                [
                    ['other/pkg', '1.0.0', '1.1.0', ''],
                    ['expo (mobile)', '51.0.0', '51.0.1', ''],
                ],
            ))
            ->and($result->getOutput())->not->toContain('react-native');
    });

    it('exits when the lockfiles match the base', function () {
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0']),
        ]);

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base]);

        expect($result->getExitCode())->toBe(1)
            ->and($result->getOutput())->toBe('')
            ->and($result->getErrorOutput())->toContain('No dependency changes to summarize.')
            ->and(commandLog($this->directory))->not->toContain('audit');
    });

    it('uses origin/HEAD when no base is passed', function () {
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0']),
        ]);
        gitSummary($this->directory, $this->repo, ['update-ref', 'refs/remotes/origin/HEAD', $base]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['livewire/livewire' => '4.2.2', 'other/pkg' => '1.0.0']));

        $result = runMaintainer($this->directory, $this->repo, ['summary'], emptyAudits(false));

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toContain('Update livewire/livewire to 4.2.2', '| livewire/livewire | 4.2.0 | 4.2.2 |');
    });

    it('rejects a base that is not a commit ref', function (array $args) {
        $result = runMaintainer($this->directory, $this->repo, $args);

        expect($result->getExitCode())->toBe(64)
            ->and($result->getOutput())->toBe('');
    })->with([
        [['summary', '--base']],
        [['summary', '--base', '--help']],
        [['summary', '--base', 'HEAD:composer.lock']],
        [['summary', 'routine']],
    ]);
});

function enableGit(string $directory): void
{
    $found = new Process(['which', 'git']);
    $found->mustRun();
    $git = trim($found->getOutput());
    symlink($git, $directory.'/bin/git');
}

/** @param array<string, string> $files */
function commitSummary(string $directory, string $repo, array $files): string
{
    foreach ($files as $path => $contents) {
        $full = $repo.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $contents);
    }
    if (! is_dir($repo.'/.git')) {
        gitSummary($directory, $repo, ['init', '-b', 'main']);
    }
    gitSummary($directory, $repo, ['add', '--', ...array_keys($files)]);
    gitSummary($directory, $repo, ['commit', '-m', 'base']);

    return trim(gitSummary($directory, $repo, ['rev-parse', 'HEAD']));
}

/** @param list<string> $args */
function gitSummary(string $directory, string $repo, array $args): string
{
    $process = new Process(['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...$args], $repo, [
        'PATH' => $directory.'/bin:'.getenv('PATH'),
        'GIT_AUTHOR_DATE' => '2026-10-01T00:00:00Z',
        'GIT_COMMITTER_DATE' => '2026-10-01T00:00:00Z',
    ]);
    $process->mustRun();

    return $process->getOutput();
}

/** @param array<string, string> $packages @param array<string, string> $dev */
function composerLock(array $packages, array $dev = []): string
{
    $rows = function (array $versions): array {
        $list = [];
        foreach ($versions as $name => $version) {
            $list[] = ['name' => $name, 'version' => 'v'.$version];
        }

        return $list;
    };

    return json_encode([
        'content-hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'packages' => $rows($packages),
        'packages-dev' => $rows($dev),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/** @param array<string, string> $packages */
function npmLock(array $packages): string
{
    $locked = ['' => ['name' => 'app']];
    foreach ($packages as $name => $version) {
        $locked['node_modules/'.$name] = ['version' => $version];
    }

    return json_encode([
        'name' => 'app',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => $locked,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/** @param array<string, string> $packages */
function bunLock(array $packages): string
{
    $lines = ["{", '  "lockfileVersion": 1,', '  "packages": {'];
    $entries = [];
    foreach ($packages as $name => $version) {
        $entries[] = '    "'.$name.'": ["'.$name.'@'.$version.'", "", {}, "sha512-abc",],';
    }
    $lines[] = implode("\n", $entries);
    $lines[] = '  },';
    $lines[] = '}';

    return implode("\n", $lines)."\n";
}

/** @return array<string, list<array<string, mixed>>> */
function emptyAudits(bool $js = true): array
{
    $fixture = [
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
    ];
    if ($js) {
        $fixture['vp pm audit --json'] = [['data' => ['vulnerabilities' => []]]];
    }

    return $fixture;
}

/** @param list<array{0: string, 1: string, 2: string, 3: string}> $rows */
function summaryText(string $title, string $what, string $meaning, array $rows): string
{
    $table = ["| Package | From | To | note |", "| --- | --- | --- | --- |"];
    foreach ($rows as [$package, $from, $to, $note]) {
        $table[] = "| {$package} | {$from} | {$to} | {$note} |";
    }

    return $title."\n\n".$what."\n\n".$meaning."\n\n".implode("\n", $table)."\n\nTested: compared the lockfiles with the base commit.\n";
}
