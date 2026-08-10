<?php

namespace App\Models;

use App\Support\StoredFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected function bannerImageUrl(): Attribute
    {
        return Attribute::get(fn () => StoredFile::url($this->banner_image));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function teachers(): BelongsToMany
    {
        // wherePivotNull('deleted_at') is essential: belongsToMany doesn't
        // apply the Enrollment model's SoftDeletes scope, so without it any
        // soft-deleted teacher row would still show up in $course->teachers.
        return $this->belongsToMany(User::class, 'enrollments')
            ->wherePivot('role_on_course', Enrollment::ROLE_TEACHER)
            ->wherePivotNull('deleted_at')
            // enrolled_at/expires_at are the merged column names —
            // migration mapped course_teacher.assigned_at → enrolled_at
            // and course_teacher.ends_at → expires_at.
            ->withPivot(['is_active', 'enrolled_at', 'expires_at', 'last_accessed_at', 'role_on_course'])
            ->withTimestamps();
    }

    /**
     * Student memberships only. See User::enrollments() for the same
     * rationale — the column is shared with teachers now, so we scope.
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->where('role_on_course', Enrollment::ROLE_STUDENT);
    }

    /**
     * All memberships regardless of role — student + teacher rows. Use when
     * you need to touch every user linked to this course (e.g. cache
     * invalidation on course update/delete).
     */
    public function courseMemberships(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function students(): BelongsToMany
    {
        // See teachers() — wherePivotNull('deleted_at') keeps soft-deleted
        // student rows out of the visible/counted list.
        return $this->belongsToMany(User::class, 'enrollments')
            ->wherePivot('role_on_course', Enrollment::ROLE_STUDENT)
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'enrolled_at', 'expires_at', 'last_accessed_at', 'role_on_course'])
            ->withTimestamps();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort_order');
    }

    /**
     * Flip is_published to true for any of this course's sections whose
     * scheduled release time has passed. Cheap single UPDATE — safe to
     * call on every course-page load.
     *
     * We deliberately KEEP scheduled_at after release — it doubles as the
     * section's "release date" record, which the student view uses to
     * auto-expand the currently-relevant section (latest past scheduled_at).
     * The "Scheduled" badge only shows while scheduled_at is in the future,
     * so keeping the date around after release doesn't affect the UI.
     */
    public function releaseScheduledSections(): int
    {
        return $this->sections()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where('is_published', false)
            ->update([
                'is_published' => true,
            ]);
    }

    /**
     * Restrict to courses the given user is allowed to see.
     * Admin: all. Anyone else: courses they're assigned as staff
     * (enrollments.role_on_course='teacher') OR enrolled in as a student
     * (enrollments.role_on_course='student', active).
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        // Home-page visibility is relationship-based, not permission-based.
        // Admin sees every active course; everyone else sees only courses
        // they teach or are enrolled in. The courses.view permission is for
        // the admin /courses management page — it does NOT widen the home
        // page listing.
        $query = $query->where('courses.is_active', true);

        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($user) {
            $outer->whereHas('teachers', fn (Builder $q) => $q->where('users.id', $user->id))
                ->orWhereHas('enrollments', function (Builder $q) use ($user) {
                    $q->where('user_id', $user->id)->where('is_active', true);
                });
        });
    }
}
