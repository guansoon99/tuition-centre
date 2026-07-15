<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_COUNTDOWN = 'countdown';
    public const TYPE_TEXT = 'text';

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'type',
        'target_date',
        'image_path',
        'scheduled_at',
        'sort_order',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'target_date' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    /**
     * Whether a non-staff user should see this section.
     *
     * If `scheduled_at` is set, it overrides `is_published` — the section
     * goes live at that moment automatically. Otherwise the manual
     * `is_published` flag is the gate.
     */
    public function isVisibleToStudents(): bool
    {
        if ($this->scheduled_at !== null) {
            return $this->scheduled_at->isPast();
        }

        return $this->is_published;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class)->orderBy('sort_order');
    }
}
