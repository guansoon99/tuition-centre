<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'code',
        'name',
        'description',
        'banner_image',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teacher')
            ->withPivot('assigned_at', 'ends_at', 'last_accessed_at')
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments')
            ->withPivot(['is_active', 'enrolled_at', 'expires_at', 'last_accessed_at'])
            ->withTimestamps();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort_order');
    }

    /**
     * Flip is_published to true (and clear scheduled_at) for any of this
     * course's sections whose scheduled release time has passed. Cheap
     * single UPDATE — safe to call on every course-page load.
     */
    public function releaseScheduledSections(): int
    {
        return $this->sections()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where('is_published', false)
            ->update([
                'is_published' => true,
                'scheduled_at' => null,
            ]);
    }

    /**
     * Restrict to courses the given user is allowed to see.
     * Admin: all. Anyone else: courses they're assigned as staff
     * (course_teacher pivot) OR enrolled in as a student.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->where('courses.is_active', true)
            ->where(function (Builder $outer) use ($user) {
                $outer->whereHas('teachers', fn (Builder $q) => $q->where('users.id', $user->id))
                    ->orWhereHas('enrollments', function (Builder $q) use ($user) {
                        $q->where('user_id', $user->id)->where('is_active', true);
                    });
            });
    }
}
