<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function packageRoot(): string
{
    return dirname(__DIR__);
}

/** @return array{0: string, 1: string} */
function newMaintainerRepo(bool $vitePlus = true): array
{
    $directory = sys_get_temp_dir().'/maintainer-'.bin2hex(random_bytes(6));
    $repo = $directory.'/repo with spaces';
    mkdir($repo.'/node_modules/.bin', 0755, true);
    mkdir($directory.'/bin', 0755, true);
    symlink(PHP_BINARY, $directory.'/bin/php');
    $manager = packageRoot().'/tests/Fixtures/dependencies/manager';
    foreach (['composer', 'npm', 'npx', 'bun'] as $name) {
        copy($manager, $directory.'/bin/'.$name);
        chmod($directory.'/bin/'.$name, 0755);
    }
    copy($manager, $repo.'/node_modules/.bin/vp');
    chmod($repo.'/node_modules/.bin/vp', 0755);
    $js = [
        'dependencies' => ['react' => '^19.0'],
        'devDependencies' => ['@scope/tool' => '^1.0'],
        'packageManager' => 'npm@11.12.0',
    ];
    if ($vitePlus) {
        $js['dependencies']['vite-plus'] = '^0.1.0';
    }
    file_put_contents($repo.'/composer.json', json_encode([
        'require' => ['livewire/livewire' => '^4.2', 'other/pkg' => '^1.0'],
        'require-dev' => ['dev/tool' => '^1.0'],
    ]));
    file_put_contents($repo.'/package.json', json_encode($js));
    file_put_contents($repo.'/package-lock.json', json_encode([
        'name' => 'app',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => ['' => []],
    ]));
    file_put_contents($repo.'/vet.json', '{"minimum-release-age":7,"minimum-release-age-exclude":["existing/pkg"]}');

    return [$directory, $repo];
}

function removeMaintainerRepo(string $directory): void
{
    if ($directory !== '' && is_dir($directory)) {
        (new Process(['rm', '-rf', '--', $directory]))->mustRun();
    }
}

/** @param list<string> $args @param array<string, mixed> $fixture @param array<string, string> $env */
function runMaintainer(string $directory, string $repo, array $args, array $fixture = [], array $env = []): Process
{
    $fixturePath = $directory.'/fixture.json';
    file_put_contents($fixturePath, json_encode($fixture));
    @unlink($fixturePath.'.counts');
    $process = new Process([PHP_BINARY, packageRoot().'/bin/maintainer', 'dependencies', ...$args], $repo, [
        'PATH' => $directory.'/bin',
        'DEPENDENCY_TEST_LOG' => $directory.'/commands',
        'DEPENDENCY_TEST_FIXTURE' => $fixturePath,
        ...$env,
    ]);
    $process->run();

    return $process;
}

function commandLog(string $directory): string
{
    $path = $directory.'/commands';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array<string, list<array<string, mixed>>> */
function emptyDetect(): array
{
    return [
        'composer outdated --locked --major-only --format=json' => [['data' => ['locked' => []]]],
        'composer audit --locked --format=json' => [['data' => ['advisories' => []]]],
        'vp outdated --format json' => [['data' => []]],
        'vp pm audit --json' => [['data' => ['vulnerabilities' => []]]],
    ];
}

/** @return array<string, list<array<string, mixed>>> */
function livewireFinding(): array
{
    return ['advisories' => ['livewire/livewire' => [
        ['advisoryId' => 'PKSA-xhv3-nr94-h8db', 'title' => 'Livewire advisory one', 'link' => 'https://packagist.org/security-advisories/PKSA-xhv3-nr94-h8db'],
        ['advisoryId' => 'PKSA-bgw4-5zmg-2njg', 'title' => 'Livewire advisory two', 'link' => 'https://packagist.org/security-advisories/PKSA-bgw4-5zmg-2njg'],
    ]]];
}
