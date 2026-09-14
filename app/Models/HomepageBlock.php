<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One editable block of the public homepage, keyed by its name ("features",
 * "reviews", ...). The shape of `data` is declared in
 * App\Support\HomepageContent, which is the only thing that should read or
 * write these rows.
 */
class HomepageBlock extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'data'];

    protected $casts = [
        'data' => 'array',
    ];
}
