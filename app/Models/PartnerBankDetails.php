<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerBankDetails extends Model
{
    protected $fillable = ['bank_name', 'account_name', 'account_number'];

    protected $hidden = ['bank_name', 'account_name', 'account_number'];

    protected function casts(): array
    {
        return [
            'bank_name' => 'encrypted',
            'account_name' => 'encrypted',
            'account_number' => 'encrypted',
        ];
    }
}
