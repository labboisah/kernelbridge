<?php

namespace App\Domain\Licensing\Exceptions;

use RuntimeException;

final class InstallationVerificationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
