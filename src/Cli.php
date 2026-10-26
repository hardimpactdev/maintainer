<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class Cli
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        try {
            (new self)->run($argv);

            return 0;
        } catch (CommandFailed $failed) {
            return $failed->exitCode;
        } catch (Failure $failure) {
            fwrite(STDERR, $failure->getMessage()."\n");

            return $failure->exitCode;
        }
    }

    /** @param list<string> $argv */
    public function run(array $argv): void
    {
        $args = array_slice($argv, 1);
        if (($args[0] ?? '') !== 'dependencies') {
            $this->usage();
        }
        $mode = $args[1] ?? '';
        $rest = array_slice($args, 2);
        $config = Config::load(getcwd() ?: '.');
        $runner = new Runner;
        if ($mode === 'routine' || $mode === 'detect') {
            $side = $this->optionalSide($rest);
            $mode === 'routine' ? $runner->routine($config, $side) : $runner->detect($config, $side);

            return;
        }
        if ($mode === 'check') {
            if ($rest !== []) {
                $this->usage();
            }
            $runner->check($config);

            return;
        }
        if ($mode !== 'package') {
            $this->usage();
        }
        [$side, $manager, $package, $version, $purpose, $ids] = $this->packageArgs($rest);
        $runner->package($config, $side, $manager, $package, $version, $purpose, $ids);
    }

    /** @param list<string> $rest */
    private function optionalSide(array $rest): ?string
    {
        if ($rest === []) {
            return null;
        }
        if (count($rest) === 1 && $this->sideName($rest[0])) {
            return $rest[0];
        }
        $this->usage();
    }

    /**
     * @param list<string> $rest
     * @return array{0: ?string, 1: string, 2: string, 3: string, 4: string, 5: list<string>}
     */
    private function packageArgs(array $rest): array
    {
        $side = null;
        if (isset($rest[0]) && ! in_array($rest[0], ['composer', 'js'], true)) {
            if (! $this->sideName($rest[0])) {
                throw new Failure('Invalid single-package target');
            }
            $side = array_shift($rest);
        }
        if (count($rest) < 3) {
            $this->usage();
        }
        [$manager, $package, $version] = [$rest[0], $rest[1], $rest[2]];
        $rest = array_slice($rest, 3);
        if (! in_array($manager, ['composer', 'js'], true)) {
            throw new Failure('Invalid single-package target');
        }
        $name = $manager === 'composer'
            ? '~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D'
            : '~^(?:@[a-z0-9][a-z0-9_.-]*/)?[a-z0-9][a-z0-9_.-]*$~D';
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version) !== 1 || preg_match($name, $package) !== 1) {
            throw new Failure('Invalid single-package target');
        }
        if ($rest === []) {
            return [$side, $manager, $package, $version, 'major', []];
        }
        if ($rest[0] === '--advisory') {
            if (count($rest) % 2 !== 0) {
                $this->usage();
            }
            $ids = [];
            for ($i = 0; $i < count($rest); $i += 2) {
                if ($rest[$i] !== '--advisory') {
                    $this->usage();
                }
                $ids[] = $rest[$i + 1];
            }
            $purpose = 'security';
        } elseif (in_array($rest[0], ['major', 'security'], true)) {
            $purpose = array_shift($rest);
            $ids = $rest;
        } else {
            throw new Failure('Invalid single-package target');
        }
        if (($purpose === 'security') !== ($ids !== [])) {
            throw new Failure('Invalid single-package target');
        }
        foreach ($ids as $id) {
            if (preg_match('~^[A-Za-z0-9][A-Za-z0-9_.:/-]*$~D', $id) !== 1) {
                throw new Failure('Invalid advisory ID');
            }
        }

        return [$side, $manager, $package, $version, $purpose, $ids];
    }

    private function sideName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name) === 1
            && ! in_array($name, ['composer', 'js', 'routine', 'detect', 'package', 'check'], true);
    }

    private function usage(): never
    {
        throw new Failure('Usage: maintainer dependencies routine [side] | detect [side] | package [side] <composer|js> <package> <x.y.z> [--advisory ID ...] | package [side] <composer|js> <package> <x.y.z> <major|security> [advisory-id ...] | check');
    }
}
