<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Pos = 'pos';
    case Card = 'card';
    case Other = 'other';
}
