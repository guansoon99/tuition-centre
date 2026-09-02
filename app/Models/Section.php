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
        'published_at',
        'never_collapses',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'target_date' => 'datetime',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'never_collapses' => 'boolean',
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

    /**
     * Whether this section folds itself away for a user with no preference.
     *
     * A section stays open for the day it was published and the seven days
     * after it, then folds — so publish on a Wednesday and it is open all of
     * that Wednesday and every day up to and including the next one, folding
     * overnight into the Thursday.
     *
     * Both sides are compared at day granularity, which is the point: an
     * exact 7x24h window folded the section at whatever clock time it had
     * been published at, so content disappeared mid-afternoon on a day it had
     * been readable all morning. Now it only ever changes at midnight, and
     * the time of day a section was published never matters.
     *
     * Keyed on published_at, not scheduled_at: scheduling is optional and
     * most sections have none, so a rule reading scheduled_at would simply
     * never fire for them.
     *
     * Null means never published — only staff can see such a section, and
     * folding away a draft the moment it is created helps nobody, so it
     * stays open.
     *
     * `never_collapses` opts a section out entirely: a standing announcement,
     * a countdown to an exam, a coursework brief that matters all term. It is
     * an explicit choice on the section rather than something inferred from
     * the materials inside, because announcements and assignments go stale
     * too — see the migration for the full reasoning.
     *
     * Anything a user has an explicit preference for never reaches this;
     * see Student\CourseController::show().
     */
    public function startsCollapsedByDefault(): bool
    {
        if ($this->never_collapses || $this->published_at === null) {
            return false;
        }

        return $this->published_at->startOfDay()->lt(now()->startOfDay()->subWeek());
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
