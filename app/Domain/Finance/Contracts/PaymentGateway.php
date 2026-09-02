<?php

namespace App\Domain\Finance\Contracts;

interface PaymentGateway
{
    public function provider(): string;

    public function verify(string $reference): array;
}
