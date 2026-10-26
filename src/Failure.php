<?php

declare(strict_types=1);

namespace Hardimpactdev\Maintainer;

class Failure extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 64)
    {
        parent::__construct($message);
    }
}

final class CommandFailed extends Failure
{
    public function __construct(int $exitCode)
    {
        parent::__construct('', $exitCode);
    }
}
