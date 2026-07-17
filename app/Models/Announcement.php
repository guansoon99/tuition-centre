<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'audience',
        'course_id',
        'starts_at',
        'ends_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

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
            $base = match ($this->audience) {
                'all' => 'Everyone',
                'students' => 'Students',
                'teachers' => 'Teachers',
                default => ucfirst($this->audience),
            };
            $scope = $this->course_id ? ' — '.($this->course?->code ?? "Course #{$this->course_id}") : '';
            return $base.$scope;
        });
    }

    // Existing admin views expect `sent_at`. Alias to created_at so we
    // don't need to touch every table cell.
    protected function sentAt(): Attribute
    {
        return Attribute::get(fn () => $this->created_at);
    }
}
