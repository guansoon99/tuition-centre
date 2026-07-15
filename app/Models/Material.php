<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_PDF = 'pdf';
    public const TYPE_EXTERNAL_LINK = 'external_link';
    public const TYPE_VIDEO_LINK = 'video_link';
    public const TYPE_TEXT = 'text';
    public const TYPE_COUNTDOWN = 'countdown';

    protected $fillable = [
        'section_id',
        'title',
        'type',
        'file_path',
        'external_url',
        'body',
        'target_date',
        'file_size_bytes',
        'sort_order',
        'is_published',
        'published_at',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'target_date' => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }
}
