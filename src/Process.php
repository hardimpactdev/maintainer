<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

final class Process
{
    public function __construct(private readonly string $cwd) {}

    /** @param list<string> $args */
    public function run(array $args): void
    {
        $code = $this->execute($args, null);
        if ($code !== 0) {
            throw new CommandFailed($code);
        }
    }

    /**
     * Run a bootstrap command. Its stdout and stderr both stay off the JSON stdout.
     *
     * @param list<string> $args
     */
    public function runToStderr(array $args): void
    {
        $out = tmpfile();
        if ($out === false) {
            throw new Failure('Could not capture '.$args[0], 70);
        }
        $code = $this->execute($args, [$out, STDERR]);
        rewind($out);
        $text = stream_get_contents($out);
        fclose($out);
        if (! is_string($text)) {
            throw new Failure('Could not capture '.$args[0], 70);
        }
        if ($text !== '') {
            fwrite(STDERR, $text);
        }
        if ($code !== 0) {
            throw new CommandFailed($code);
        }
    }

    /**
     * @param list<string> $args
     * @return array<mixed>
     */
    public function json(array $args): array
    {
        [$code, $text, $error] = $this->capture($args);
        $this->guard($args, $code, $text, $error);
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('Invalid JSON from '.implode(' ', $args).': '.$error, 70);
        }
        if (! is_array($data) || array_key_exists('error', $data)) {
            throw new Failure('Dependency command failed: '.implode(' ', $args).': '.$error, 70);
        }

        return $data;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    public function capture(array $args): array
    {
        $out = tmpfile();
        $err = tmpfile();
        if ($out === false || $err === false) {
            throw new Failure('Could not capture '.$args[0], 70);
        }
        $code = $this->execute($args, [$out, $err]);
        rewind($out);
        rewind($err);
        $text = stream_get_contents($out, 1048577);
        $error = stream_get_contents($err, 8192);
        fclose($out);
        fclose($err);
        if (! is_string($text) || ! is_string($error)) {
            throw new Failure('Could not capture '.$args[0], 70);
        }
        $text = preg_replace("/\x1b\[[0-9;]*m/", '', $text) ?? $text;

        return [$code, $text, $error];
    }

    /** @param list<string> $args */
    public function guard(array $args, int $code, string $text, string $error): void
    {
        if (strlen($text) > 1048576 || $code < 0 || $code > 3) {
            throw new Failure('Dependency command failed: '.implode(' ', $args).': '.$error, 70);
        }
    }

    /**
     * @param list<string> $args
     * @param array{0: resource, 1: resource}|null $streams
     */
    private function execute(array $args, ?array $streams): int
    {
        $spec = $streams === null
            ? [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR]
            : [0 => ['file', '/dev/null', 'r'], 1 => $streams[0], 2 => $streams[1]];
        $process = proc_open($args, $spec, $pipes, $this->cwd);
        if (! is_resource($process)) {
            throw new Failure('Could not start '.$args[0], 70);
        }

        return proc_close($process);
    }
}
