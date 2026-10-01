<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ClientSetupBundle extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return ['secrets' => 'encrypted:array'];
    }
}
