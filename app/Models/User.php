<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'ic_number',
        'candidate_number',
        'password',
        'is_active',
        'last_login_at',
        'notes',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function enrolledCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments')
            ->withPivot(['is_active', 'enrolled_at', 'expires_at', 'last_accessed_at'])
            ->withTimestamps();
    }

    public function taughtCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_teacher')
            ->withPivot('assigned_at')
            ->withTimestamps();
    }

    public function uploadedMaterials(): HasMany
    {
        return $this->hasMany(Material::class, 'uploaded_by_user_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }

    /**
     * Notifications query filtered by the announcement visibility window
     * stored in `data->starts_at` and `data->ends_at` (nullable both sides).
     */
    public function visibleAnnouncements()
    {
        $now = now();

        $q = \App\Models\Announcement::query()
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });

        // Admin sees every announcement (matches the current send-a-copy-
        // to-admins behavior).
        if ($this->hasRole('admin')) {
            return $q->orderByDesc('created_at');
        }

        $enrolledIds = $this->enrollments()->where('is_active', true)->pluck('course_id')->all();
        $taughtIds = $this->taughtCourses()->pluck('courses.id')->all();
        $relatedIds = array_values(array_unique(array_merge($enrolledIds, $taughtIds)));
        $isStudent = $this->hasRole('student');
        $isTeacher = ! empty($taughtIds);

        return $q->where(function ($sub) use ($enrolledIds, $taughtIds, $relatedIds, $isStudent, $isTeacher) {
            // audience='all', no course scope → everyone sees
            $sub->orWhere(function ($qq) {
                $qq->where('audience', 'all')->whereNull('course_id');
            });

            // audience='all', course-scoped → user is enrolled in OR teaches that course
            if (! empty($relatedIds)) {
                $sub->orWhere(function ($qq) use ($relatedIds) {
                    $qq->where('audience', 'all')->whereIn('course_id', $relatedIds);
                });
            }

            // audience='students', no course → any student
            if ($isStudent) {
                $sub->orWhere(function ($qq) {
                    $qq->where('audience', 'students')->whereNull('course_id');
                });
            }

            // audience='students', course-scoped → user is enrolled in that course
            if (! empty($enrolledIds)) {
                $sub->orWhere(function ($qq) use ($enrolledIds) {
                    $qq->where('audience', 'students')->whereIn('course_id', $enrolledIds);
                });
            }

            // audience='teachers', no course → user teaches any course
            if ($isTeacher) {
                $sub->orWhere(function ($qq) {
                    $qq->where('audience', 'teachers')->whereNull('course_id');
                });
            }

            // audience='teachers', course-scoped → user teaches that course
            if (! empty($taughtIds)) {
                $sub->orWhere(function ($qq) use ($taughtIds) {
                    $qq->where('audience', 'teachers')->whereIn('course_id', $taughtIds);
                });
            }
        })->orderByDesc('created_at');
    }

    public function teaches(Course $course): bool
    {
        return $this->taughtCourses()->whereKey($course->id)->exists();
    }

    public function isEnrolledIn(Course $course, bool $activeOnly = true): bool
    {
        $query = $this->enrollments()->where('course_id', $course->id);

        if ($activeOnly) {
            $query->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return $query->exists();
    }
}
