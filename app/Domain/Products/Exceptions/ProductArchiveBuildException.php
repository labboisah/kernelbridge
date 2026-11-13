<?php

namespace App\Domain\Products\Exceptions;

use RuntimeException;

final class ProductArchiveBuildException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}
