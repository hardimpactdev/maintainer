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
                'Maintenance update for '.gmdate('F Y'),
                'This is a routine maintenance update. The packages below were updated to their newest safe versions.',
                [
                    ['livewire/livewire', '4.2.0', '4.2.1', ''],
                    ['react', '19.0.0', '19.1.0', ''],
                ],
            ))
            ->and($result->getOutput())->not->toContain('polyfill', 'loose-envify', 'Swamp', '{', 'Tested:', 'Action needed', 'Security fixes')
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
                'This updates other/pkg from 1.2.3 to 2.0.0.',
                [['other/pkg', '1.2.3', '2.0.0', 'major']],
                ['other/pkg 1.2.3 → 2.0.0 is a major update. Check its upgrade notes before you deploy.'],
            ))
            ->and($result->getOutput())->not->toContain('Tested:', 'Security fixes');
    });

    it('keeps a routine title when one of several updates is major', function () {
        $composer = json_decode((string) file_get_contents($this->repo.'/composer.json'), true);
        $composer['require']['laravel/framework'] = '^13.38';
        $encoded = json_encode($composer);
        file_put_contents($this->repo.'/composer.json', $encoded);
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => $encoded,
            'composer.lock' => composerLock(['laravel/framework' => '13.38.0', 'livewire/livewire' => '4.2.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0']),
        ]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['laravel/framework' => '14.0.0', 'livewire/livewire' => '4.2.1']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], emptyAudits(false));

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Maintenance update for '.gmdate('F Y'),
                'This is a routine maintenance update. The packages below were updated to their newest safe versions.',
                [
                    ['laravel/framework', '13.38.0', '14.0.0', 'major'],
                    ['livewire/livewire', '4.2.0', '4.2.1', ''],
                ],
                ['laravel/framework 13.38.0 → 14.0.0 is a major update. Check its upgrade notes before you deploy.'],
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
                'This updates @scope/tool from 0.4.0 to 0.5.0.',
                [['@scope/tool', '0.4.0', '0.5.0', 'major']],
                ['@scope/tool 0.4.0 → 0.5.0 is a major update. Check its upgrade notes before you deploy.'],
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
            'composer audit --locked --format=json' => [
                ['data' => ['advisories' => []]],
                ['code' => 1, 'data' => livewireFinding()],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update livewire/livewire to 4.5.0 (security fix)',
                'This updates livewire/livewire from 4.2.0 to 4.5.0.',
                [[
                    'livewire/livewire',
                    '4.2.0',
                    '4.5.0',
                    'security fix [PKSA-bgw4-5zmg-2njg](https://packagist.org/security-advisories/PKSA-bgw4-5zmg-2njg), [PKSA-xhv3-nr94-h8db](https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db)',
                ]],
                [],
                ['livewire/livewire: [PKSA-bgw4-5zmg-2njg](https://packagist.org/security-advisories/PKSA-bgw4-5zmg-2njg), [PKSA-xhv3-nr94-h8db](https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db)'],
            ))
            ->and($result->getOutput())->not->toContain('Tested:', 'Action needed')
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
                'Maintenance update for '.gmdate('F Y'),
                'This is a routine maintenance update. The packages below were updated to their newest safe versions.',
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

    it('does not call a bump a security fix while the advisory still applies', function () {
        $finding = npmFinding('lodash', [
            [1065, 'GHSA-35jh-r3h4-6jhm', 'Prototype pollution in lodash', '<4.17.21'],
            [1080, 'GHSA-29mw-wpgm-hmr9', 'Command injection in lodash', '<4.17.21'],
        ]);
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['lodash'] = '^4.17.19';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0', 'lodash' => '4.17.19']),
        ]);
        file_put_contents($this->repo.'/package-lock.json', npmLock(['react' => '19.0.0', 'lodash' => '4.17.20']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [['code' => 1, 'data' => $finding]],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update lodash to 4.17.20',
                'This updates lodash from 4.17.19 to 4.17.20.',
                [['lodash', '4.17.19', '4.17.20', '']],
            ))
            ->and($result->getOutput())->not->toContain('security fix', 'GHSA-35jh-r3h4-6jhm', 'GHSA-29mw-wpgm-hmr9');
    });

    it('reads a bun lock whose instance key is not the descriptor name', function () {
        unlink($this->repo.'/package-lock.json');
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['packageManager'] = 'bun@1.4.0';
        $manifest['dependencies'] = [
            'alias' => 'npm:is-number@7.0.0',
            'is-number' => '^7.0.0',
            'is-odd' => '^3.0.1',
            'vite-plus' => '^0.1.0',
        ];
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $lock = bunLock([
            'alias' => ['descriptor' => 'is-number@7.0.0'],
            'is-number' => '7.0.0',
            'is-odd' => ['descriptor' => 'is-odd@3.0.1', 'dependencies' => ['is-number' => '^6.0.0']],
            'is-odd/is-number' => ['descriptor' => 'is-number@6.0.0'],
            'vite-plus' => '0.1.0',
        ]);
        $base = commitSummary($this->directory, $this->repo, ['bun.lock' => $lock]);

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base]);

        expect($result->getExitCode())->toBe(1)
            ->and($result->getOutput())->toBe('')
            ->and($result->getErrorOutput())->toContain('No dependency changes to summarize.')
            ->and($result->getErrorOutput())->not->toContain('Invalid bun.lock');
    });

    it('lists a direct dependency removed from the manifest and the lockfile', function () {
        $composer = json_decode((string) file_get_contents($this->repo.'/composer.json'), true);
        $package = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => json_encode($composer),
            'package.json' => json_encode($package),
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => npmLock(['react' => '19.0.0', '@scope/tool' => '1.0.0']),
        ]);
        unset($composer['require']['other/pkg'], $package['dependencies']['react']);
        file_put_contents($this->repo.'/composer.json', json_encode($composer));
        file_put_contents($this->repo.'/package.json', json_encode($package));
        file_put_contents($this->repo.'/composer.lock', composerLock(['livewire/livewire' => '4.2.0']));
        file_put_contents($this->repo.'/package-lock.json', npmLock(['@scope/tool' => '1.0.0']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], emptyAudits());

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Maintenance update for '.gmdate('F Y'),
                'This is a routine maintenance update. The packages below were updated to their newest safe versions.',
                [
                    ['other/pkg', '1.0.0', '—', ''],
                    ['react', '19.0.0', '—', ''],
                ],
            ));
    });

    it('attributes a cleared transitive npm advisory to the direct dependency', function () {
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['express'] = '^4.18.2';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $before = npmLock([
            'express' => '4.18.2',
            'node_modules/express/node_modules/qs' => '6.11.0',
        ]);
        $after = npmLock([
            'express' => '4.18.2',
            'node_modules/qs' => '6.16.1',
        ]);
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => $before,
        ]);
        file_put_contents($this->repo.'/package-lock.json', $after);
        $qs = [
            [1090, 'GHSA-hrpp-h998-j3pp', 'qs prototype pollution', '<6.14.1'],
            [1091, 'GHSA-6rw7-vpxm-498p', 'qs arrayLimit bypass', '<6.14.2'],
            [1092, 'GHSA-qqgx-2p2h-9c37', 'qs parse depth', '<6.15.0'],
        ];

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['data' => ['vulnerabilities' => []]],
                ['code' => 1, 'data' => npmViaFinding('express', 'qs', $qs)],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toContain('Update express to 4.18.2 (security fix)')
            ->and($result->getOutput())->toContain('This updates express to 4.18.2.')
            ->and($result->getOutput())->toContain("Security fixes\n\nexpress: [1090](https://github.com/advisories/GHSA-hrpp-h998-j3pp), [1091](https://github.com/advisories/GHSA-6rw7-vpxm-498p), [1092](https://github.com/advisories/GHSA-qqgx-2p2h-9c37)")
            ->and($result->getOutput())->toContain('| express | 4.18.2 | 4.18.2 | security fix [1090](https://github.com/advisories/GHSA-hrpp-h998-j3pp), [1091](https://github.com/advisories/GHSA-6rw7-vpxm-498p), [1092](https://github.com/advisories/GHSA-qqgx-2p2h-9c37) |')
            ->and($result->getOutput())->not->toContain('| qs |', 'Tested:', 'Action needed');
    });

    it('keeps a nested npm copy that changes under an unchanged direct dependency', function () {
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['express'] = '^4.18.2';
        $manifest['dependencies']['qs'] = '^6.16.1';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $before = npmLock([
            'express' => '4.18.2',
            'qs' => '6.16.1',
            'node_modules/express/node_modules/qs' => '6.11.0',
        ]);
        $after = npmLock([
            'express' => '4.18.2',
            'qs' => '6.16.1',
        ]);
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0', 'other/pkg' => '1.0.0']),
            'package-lock.json' => $before,
        ]);
        file_put_contents($this->repo.'/package-lock.json', $after);
        $qs = [[1090, 'GHSA-hrpp-h998-j3pp', 'qs prototype pollution', '<6.14.1']];

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['data' => ['vulnerabilities' => []]],
                ['code' => 1, 'data' => npmViaFinding('express', 'qs', $qs, true)],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toContain('Maintenance update for '.gmdate('F Y'))
            ->and($result->getOutput())->toContain('This is a routine maintenance update. The packages below were updated to their newest safe versions.')
            ->and($result->getOutput())->toContain("Security fixes\n\nexpress: [1090](https://github.com/advisories/GHSA-hrpp-h998-j3pp)\nqs: [1090](https://github.com/advisories/GHSA-hrpp-h998-j3pp)")
            ->and($result->getOutput())->toContain('| express | 4.18.2 | 4.18.2 | security fix [1090](https://github.com/advisories/GHSA-hrpp-h998-j3pp) |')
            ->and($result->getOutput())->toContain('| qs | 6.16.1 | 6.16.1 | security fix [1090](https://github.com/advisories/GHSA-hrpp-h998-j3pp) |')
            ->and($result->getOutput())->not->toContain('Tested:', 'Action needed', 'including a security fix');
    });

    it('attributes a nested bun advisory to the direct package that pulls it in', function () {
        unlink($this->repo.'/package-lock.json');
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['packageManager'] = 'bun@1.4.0';
        $manifest['dependencies'] = ['is-odd' => '^3.0.1', 'vite-plus' => '^0.1.0'];
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $before = bunLock([
            'is-odd' => ['descriptor' => 'is-odd@3.0.1', 'dependencies' => ['is-number' => '^6.0.0']],
            'is-odd/is-number' => ['descriptor' => 'is-number@6.0.0'],
            'vite-plus' => '0.1.0',
        ]);
        $after = bunLock([
            'is-odd' => ['descriptor' => 'is-odd@3.0.1', 'dependencies' => ['is-number' => '^6.0.2']],
            'is-odd/is-number' => ['descriptor' => 'is-number@6.0.2'],
            'vite-plus' => '0.1.0',
        ]);
        $base = commitSummary($this->directory, $this->repo, [
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0']),
            'bun.lock' => $before,
        ]);
        file_put_contents($this->repo.'/bun.lock', $after);
        $audit = ['is-number' => [[
            'id' => 4242,
            'url' => 'https://github.com/advisories/GHSA-is-number',
            'title' => 'is-number',
            'severity' => 'moderate',
            'vulnerable_versions' => '<6.0.2',
        ]]];

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['data' => new stdClass],
                ['code' => 1, 'data' => $audit],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toContain('Update is-odd to 3.0.1 (security fix)')
            ->and($result->getOutput())->toContain('This updates is-odd to 3.0.1.')
            ->and($result->getOutput())->toContain("Security fixes\n\nis-odd: [4242](https://github.com/advisories/GHSA-is-number)")
            ->and($result->getOutput())->toContain('| is-odd | 3.0.1 | 3.0.1 | security fix [4242](https://github.com/advisories/GHSA-is-number) |')
            ->and($result->getOutput())->not->toContain('| is-number |', 'Tested:');
    });

    it('does not call a removed direct dependency a security fix while it stays installed', function () {
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['express'] = '^4.18.2';
        $manifest['dependencies']['qs'] = '6.11.0';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $locked = npmLock([
            'express' => '4.18.2',
            'qs' => '6.11.0',
        ]);
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => (string) file_get_contents($this->repo.'/composer.json'),
            'package.json' => json_encode($manifest),
            'package-lock.json' => $locked,
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0']),
        ]);
        unset($manifest['dependencies']['qs']);
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $qs = [
            [1093, 'GHSA-w7fw-mjwx-w883', 'qs arrayLimit bypass', '<6.14.1'],
            [1094, 'GHSA-6rw7-vpxm-498p', 'qs parse depth', '<6.14.1'],
            [1095, 'GHSA-4mjr-xmp4-gh2g', 'qs prototype pollution', '<6.10.3'],
        ];

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['code' => 1, 'data' => npmViaFinding('express', 'qs', $qs, false)],
                ['code' => 1, 'data' => npmViaFinding('express', 'qs', $qs, true)],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Remove qs',
                'This removes qs 6.11.0.',
                [['qs', '6.11.0', '—', '']],
            ))
            ->and($result->getOutput())->not->toContain('security fix', 'GHSA-w7fw-mjwx-w883', 'GHSA-6rw7-vpxm-498p', 'GHSA-4mjr-xmp4-gh2g');
    });

    it('lists removals when composer audit skips an empty lock', function () {
        $composer = [
            'require' => ['psr/log' => '^3.0'],
            'require-dev' => ['psr/container' => '^2.0'],
        ];
        file_put_contents($this->repo.'/composer.json', json_encode($composer));
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => json_encode($composer),
            'composer.lock' => composerLock(['psr/log' => '3.0.1'], ['psr/container' => '2.0.2']),
            'package.json' => (string) file_get_contents($this->repo.'/package.json'),
            'package-lock.json' => (string) file_get_contents($this->repo.'/package-lock.json'),
        ]);
        file_put_contents($this->repo.'/composer.json', json_encode(['require' => [], 'require-dev' => []]));
        file_put_contents($this->repo.'/composer.lock', composerLock([], []));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [
                ['code' => 0, 'raw' => ''],
                ['data' => ['advisories' => []]],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Maintenance update for '.gmdate('F Y'),
                'This is a routine maintenance update. The packages below were updated to their newest safe versions.',
                [
                    ['psr/container', '2.0.2', '—', ''],
                    ['psr/log', '3.0.1', '—', ''],
                ],
            ))
            ->and($result->getErrorOutput())->not->toContain('Invalid JSON');
    });

    it('lists removals when one path in a multi-path repo has an empty composer lock', function () {
        unlink($this->repo.'/composer.json');
        mkdir($this->repo.'/server');
        $composer = [
            'require' => ['psr/log' => '^3.0'],
            'require-dev' => ['psr/container' => '^2.0'],
        ];
        file_put_contents($this->repo.'/server/composer.json', json_encode($composer));
        file_put_contents($this->repo.'/maintainer.json', json_encode(['paths' => ['.', 'server']]));
        $base = commitSummary($this->directory, $this->repo, [
            'maintainer.json' => (string) file_get_contents($this->repo.'/maintainer.json'),
            'package.json' => (string) file_get_contents($this->repo.'/package.json'),
            'package-lock.json' => (string) file_get_contents($this->repo.'/package-lock.json'),
            'server/composer.json' => json_encode($composer),
            'server/composer.lock' => composerLock(['psr/log' => '3.0.1'], ['psr/container' => '2.0.2']),
        ]);
        file_put_contents($this->repo.'/server/composer.json', json_encode(['require' => [], 'require-dev' => []]));
        file_put_contents($this->repo.'/server/composer.lock', composerLock([], []));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [
                ['code' => 0, 'raw' => ''],
                ['data' => ['advisories' => []]],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Maintenance update for '.gmdate('F Y'),
                'This is a routine maintenance update. The packages below were updated to their newest safe versions.',
                [
                    ['psr/container (server)', '2.0.2', '—', ''],
                    ['psr/log (server)', '3.0.1', '—', ''],
                ],
            ))
            ->and($result->getErrorOutput())->not->toContain('Invalid JSON');
    });

    it('still rejects an empty composer audit when the lock lists packages', function () {
        $composer = ['require' => ['psr/log' => '^3.0']];
        file_put_contents($this->repo.'/composer.json', json_encode($composer));
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => json_encode($composer),
            'composer.lock' => composerLock(['psr/log' => '3.0.1']),
            'package.json' => (string) file_get_contents($this->repo.'/package.json'),
            'package-lock.json' => (string) file_get_contents($this->repo.'/package-lock.json'),
        ]);
        file_put_contents($this->repo.'/composer.lock', composerLock(['psr/log' => '3.0.2']));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [
                ['code' => 0, 'raw' => ''],
            ],
        ]);

        expect($result->getExitCode())->toBe(70)
            ->and($result->getOutput())->toBe('')
            ->and($result->getErrorOutput())->toContain('Invalid JSON from composer audit --locked --format=json');
    });

    it('attributes a bun alias advisory to the declared package', function () {
        unlink($this->repo.'/package-lock.json');
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['packageManager'] = 'bun@1.4.0';
        $manifest['dependencies'] = [
            'alias' => 'npm:lodash@4.17.20',
            'vite-plus' => '^0.1.0',
        ];
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => (string) file_get_contents($this->repo.'/composer.json'),
            'package.json' => json_encode($manifest),
            'bun.lock' => bunLock([
                'alias' => ['descriptor' => 'lodash@4.17.20'],
                'vite-plus' => '0.1.0',
            ]),
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0']),
        ]);
        file_put_contents($this->repo.'/bun.lock', bunLock([
            'alias' => ['descriptor' => 'lodash@4.17.21'],
            'vite-plus' => '0.1.0',
        ]));
        $audit = ['lodash' => [[
            'id' => 1065,
            'url' => 'https://github.com/advisories/GHSA-35jh-r3h4-6jhm',
            'title' => 'Prototype pollution in lodash',
            'severity' => 'high',
            'vulnerable_versions' => '<4.17.21',
        ]]];

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['data' => new stdClass],
                ['code' => 1, 'data' => $audit],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toContain('Update alias to 4.17.21 (security fix)')
            ->and($result->getOutput())->toContain('This updates alias from 4.17.20 to 4.17.21.')
            ->and($result->getOutput())->toContain("Security fixes\n\nalias: [1065](https://github.com/advisories/GHSA-35jh-r3h4-6jhm)")
            ->and($result->getOutput())->toContain('| alias | 4.17.20 | 4.17.21 | security fix [1065](https://github.com/advisories/GHSA-35jh-r3h4-6jhm) |')
            ->and($result->getErrorOutput())->not->toContain('Cannot attach bun advisory', 'lodash');
    });

    it('lists cleared advisories on an npm alias', function () {
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['alias'] = 'npm:lodash@4.17.20';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $alias = [
            'name' => 'lodash',
            'version' => '4.17.20',
            'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.20.tgz',
            'integrity' => 'sha512-PlhdFcillOINfeV7Ni6oF1TAEayyZBoZ8bcshTHqOYJYlrqzRK5hagpagky5o4HfCzzd1TRkXPMFq6cKk9rGmA==',
            'license' => 'MIT',
        ];
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => (string) file_get_contents($this->repo.'/composer.json'),
            'package.json' => json_encode($manifest),
            'package-lock.json' => npmLock(['alias' => $alias, 'vite-plus' => '0.1.0']),
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0']),
        ]);
        $manifest['dependencies']['alias'] = 'npm:lodash@4.17.21';
        $alias['version'] = '4.17.21';
        $alias['resolved'] = 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        file_put_contents($this->repo.'/package-lock.json', npmLock(['alias' => $alias, 'vite-plus' => '0.1.0']));
        $cleared = [
            [1065, 'GHSA-35jh-r3h4-6jhm', 'Prototype pollution in lodash', '<4.17.21'],
            [1080, 'GHSA-29mw-wpgm-hmr9', 'Command injection in lodash', '<4.17.21'],
        ];

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['data' => ['vulnerabilities' => []]],
                ['code' => 1, 'data' => npmAliasFinding('lodash', 'alias', $cleared)],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toBe(summaryText(
                'Update alias to 4.17.21 (security fix)',
                'This updates alias from 4.17.20 to 4.17.21.',
                [[
                    'alias',
                    '4.17.20',
                    '4.17.21',
                    'security fix [1065](https://github.com/advisories/GHSA-35jh-r3h4-6jhm), [1080](https://github.com/advisories/GHSA-29mw-wpgm-hmr9)',
                ]],
                [],
                ['alias: [1065](https://github.com/advisories/GHSA-35jh-r3h4-6jhm), [1080](https://github.com/advisories/GHSA-29mw-wpgm-hmr9)'],
            ));
    });

    it('does not call open advisories fixed when a direct package becomes an npm alias', function () {
        $manifest = json_decode((string) file_get_contents($this->repo.'/package.json'), true);
        $manifest['dependencies']['lodash'] = '4.17.20';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        $open = [
            [1065, 'GHSA-35jh-r3h4-6jhm', 'Prototype pollution in lodash', '<4.17.21'],
            [1080, 'GHSA-29mw-wpgm-hmr9', 'Command injection in lodash', '<4.17.21'],
            [1106, 'GHSA-jf85-cpcp-j695', 'Prototype pollution in lodash.set', '*'],
            [1107, 'GHSA-4xc9-xhrj-v574', 'Command injection in lodash.template', '*'],
            [1108, 'GHSA-xxjr-mmjv-4gpg', 'Prototype pollution in lodash', '*'],
        ];
        $base = commitSummary($this->directory, $this->repo, [
            'composer.json' => (string) file_get_contents($this->repo.'/composer.json'),
            'package.json' => json_encode($manifest),
            'package-lock.json' => npmLock(['lodash' => '4.17.20', 'vite-plus' => '0.1.0']),
            'composer.lock' => composerLock(['livewire/livewire' => '4.2.0']),
        ]);
        unset($manifest['dependencies']['lodash']);
        $manifest['dependencies']['alias'] = 'npm:lodash@4.17.20';
        file_put_contents($this->repo.'/package.json', json_encode($manifest));
        file_put_contents($this->repo.'/package-lock.json', npmLock([
            'alias' => [
                'name' => 'npm:lodash@4.17.20',
                'version' => '4.17.20',
                'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.20.tgz',
                'integrity' => 'sha512-PlhdFcillOINfeV7Ni6oF1TAEayyZBoZ8bcshTHqOYJYlrqzRK5hagpagky5o4HfCzzd1TRkXPMFq6cKk9rGmA==',
            ],
            'vite-plus' => '0.1.0',
        ]));

        $result = runMaintainer($this->directory, $this->repo, ['summary', '--base', $base], [
            'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
            'vp pm audit --json' => [
                ['code' => 1, 'data' => npmAliasFinding('lodash', 'alias', $open)],
                ['code' => 1, 'data' => npmFinding('lodash', $open)],
            ],
        ]);

        expect($result->getExitCode())->toBe(0, $result->getErrorOutput().$result->getOutput())
            ->and($result->getOutput())->toContain('| alias | — | 4.17.20 |  |')
            ->and($result->getOutput())->toContain('| lodash | 4.17.20 | — |  |')
            ->and($result->getOutput())->not->toContain('security fix', 'GHSA-35jh-r3h4-6jhm', 'including a security fix');
    });

});

/** @param list<array{0: int, 1: string, 2: string, 3: string}> $advisories */
function npmAliasFinding(string $installed, string $alias, array $advisories): array
{
    $finding = npmFinding($installed, $advisories);
    $finding['vulnerabilities'][$installed]['nodes'] = ['node_modules/'.$alias];
    $finding['vulnerabilities'][$installed]['isDirect'] = true;

    return $finding;
}

function npmFinding(string $name, array $advisories): array
{
    $via = [];
    foreach ($advisories as [$source, $ghsa, $title, $range]) {
        $via[] = [
            'source' => $source,
            'name' => $name,
            'dependency' => $name,
            'title' => $title,
            'url' => 'https://github.com/advisories/'.$ghsa,
            'severity' => 'high',
            'range' => $range,
        ];
    }

    return ['auditReportVersion' => 2, 'vulnerabilities' => [
        $name => [
            'name' => $name,
            'severity' => 'high',
            'isDirect' => true,
            'via' => $via,
            'effects' => [],
            'range' => $advisories[0][3],
            'nodes' => ['node_modules/'.$name],
            'fixAvailable' => true,
        ],
    ]];
}

/** @param list<array{0: int, 1: string, 2: string, 3: string}> $advisories */
function npmViaFinding(string $direct, string $transitive, array $advisories, bool $transitiveIsDirect = false): array
{
    $via = [];
    foreach ($advisories as [$source, $ghsa, $title, $range]) {
        $via[] = [
            'source' => $source,
            'name' => $transitive,
            'dependency' => $transitive,
            'title' => $title,
            'url' => 'https://github.com/advisories/'.$ghsa,
            'severity' => 'high',
            'range' => $range,
        ];
    }
    $vulnerabilities = [
        $direct => [
            'name' => $direct,
            'severity' => 'high',
            'isDirect' => true,
            'via' => [$transitive],
            'effects' => [],
            'range' => '*',
            'nodes' => ['node_modules/'.$direct],
            'fixAvailable' => true,
        ],
        $transitive => [
            'name' => $transitive,
            'severity' => 'high',
            'isDirect' => $transitiveIsDirect,
            'via' => $via,
            'effects' => [$direct],
            'range' => $advisories[0][3],
            'nodes' => ['node_modules/'.$transitive, 'node_modules/'.$direct.'/node_modules/'.$transitive],
            'fixAvailable' => true,
        ],
    ];

    return ['auditReportVersion' => 2, 'vulnerabilities' => $vulnerabilities];
}

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
        $path = str_starts_with($name, 'node_modules/') ? $name : 'node_modules/'.$name;
        $locked[$path] = is_array($version) ? $version : ['version' => $version];
    }

    return json_encode([
        'name' => 'app',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => $locked,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/** @param array<string, string|array{descriptor: string, dependencies?: array<string, string>}> $packages */
function bunLock(array $packages): string
{
    $lines = ["{", '  "lockfileVersion": 1,', '  "packages": {'];
    $entries = [];
    foreach ($packages as $name => $version) {
        if (is_array($version)) {
            $deps = $version['dependencies'] ?? [];
            $meta = $deps === [] ? '{}' : json_encode(['dependencies' => $deps], JSON_UNESCAPED_SLASHES);
            $entries[] = '    "'.$name.'": ["'.$version['descriptor'].'", "", '.$meta.', "sha512-abc",],';
            continue;
        }
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

/**
 * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows
 * @param list<string> $actions
 * @param list<string> $security
 */
function summaryText(string $title, string $intro, array $rows, array $actions = [], array $security = []): string
{
    $parts = [$title, $intro];
    if ($actions !== []) {
        $parts[] = "Action needed\n\n".implode("\n", $actions);
    }
    if ($security !== []) {
        $parts[] = "Security fixes\n\n".implode("\n", $security);
    }
    $table = ["| Package | From | To | note |", "| --- | --- | --- | --- |"];
    foreach ($rows as [$package, $from, $to, $note]) {
        $table[] = "| {$package} | {$from} | {$to} | {$note} |";
    }
    $parts[] = implode("\n", $table);

    return implode("\n\n", $parts)."\n";
}
