<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsernameCounter extends Model
{
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'last_number'];

    protected $casts = [
        'last_number' => 'integer',
    ];
}
