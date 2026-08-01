<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';

    public const TYPES = [
        self::TYPE_TEXT => 'Text',
        self::TYPE_IMAGE => 'Image',
    ];

    protected $fillable = [
        'title',
        'body',
        'type',
        'image_path',
        'audience',
        'course_id',
        'starts_at',
        'ends_at',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => \App\Support\StoredFile::url($this->image_path));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function audienceLabel(): Attribute
    {
        return Attribute::get(function () {
            return $this->audience === 'all'
                ? 'All'
                : ucwords(strtolower(str_replace(['_', '-'], ' ', $this->audience)));
        });
    }

    // Existing admin views expect `sent_at`. Alias to created_at so we
    // don't need to touch every table cell.
    protected function sentAt(): Attribute
    {
        return Attribute::get(fn () => $this->created_at);
    }
}
