<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'ic_number',
        'candidate_number',
        'password',
        'plain_password',
        'is_active',
        'last_login_at',
        'notes',
    ];

    protected $hidden = [
        'password',
        'plain_password',
        'remember_token',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    /**
     * Student-side memberships. Named `enrollments` for backwards compat —
     * the underlying `enrollments` table now also holds teacher rows
     * (`role_on_course='teacher'`), so this relationship scopes to students.
     * For teacher memberships use taughtCourses(); for a generic "any
     * membership regardless of role" use courseMemberships().
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->where('role_on_course', Enrollment::ROLE_STUDENT);
    }

    /**
     * All course memberships regardless of role — student rows AND teacher
     * rows. Use when you want to answer "is this user linked to any course?"
     * (e.g. the /users enrollment filter, where 'unenrolled' should mean
     * "not linked to any course as either student or teacher").
     */
    public function courseMemberships(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function enrolledCourses(): BelongsToMany
    {
        // wherePivotNull('deleted_at') hides soft-deleted enrollment rows —
        // belongsToMany doesn't apply the Enrollment SoftDeletes scope on
        // its own so we have to filter the pivot explicitly.
        return $this->belongsToMany(Course::class, 'enrollments')
            ->wherePivot('role_on_course', Enrollment::ROLE_STUDENT)
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'enrolled_at', 'expires_at', 'last_accessed_at', 'role_on_course'])
            ->withTimestamps();
    }

    public function taughtCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments')
            ->wherePivot('role_on_course', Enrollment::ROLE_TEACHER)
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'enrolled_at', 'expires_at', 'last_accessed_at', 'role_on_course'])
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
        // to-admins behavior). Ordered by admin-controlled drag position.
        if ($this->hasRole('admin')) {
            return $q->orderBy('sort_order')->orderBy('id');
        }

        // Reuse the cached membership set — same data teaches()/isEnrolledIn()
        // hit, so this call is free after the first authenticated request.
        $membership = $this->courseMembershipIds();
        $enrolledIds = array_keys($membership['enrolled']);
        $taughtIds = $membership['taught'];
        $relatedIds = array_values(array_unique(array_merge($enrolledIds, $taughtIds)));
        $userRoles = $this->getRoleNames()->all();

        return $q->where(function ($sub) use ($relatedIds, $userRoles) {
            // audience='all', no course scope → everyone sees
            $sub->where(function ($qq) {
                $qq->where('audience', 'all')->whereNull('course_id');
            });

            // audience='all', course-scoped → user is enrolled in OR teaches that course
            if (! empty($relatedIds)) {
                $sub->orWhere(function ($qq) use ($relatedIds) {
                    $qq->where('audience', 'all')->whereIn('course_id', $relatedIds);
                });
            }

            // audience=<role>, no course scope → user has that role
            if (! empty($userRoles)) {
                $sub->orWhere(function ($qq) use ($userRoles) {
                    $qq->whereIn('audience', $userRoles)->whereNull('course_id');
                });
            }

            // audience=<role>, course-scoped → user has that role AND related to that course
            if (! empty($userRoles) && ! empty($relatedIds)) {
                $sub->orWhere(function ($qq) use ($userRoles, $relatedIds) {
                    $qq->whereIn('audience', $userRoles)->whereIn('course_id', $relatedIds);
                });
            }
        })->orderBy('sort_order')->orderBy('id');
    }

    public function teaches(Course $course): bool
    {
        return in_array($course->id, $this->courseMembershipIds()['taught'], true);
    }

    public function isEnrolledIn(Course $course, bool $activeOnly = true): bool
    {
        // Fast path: cached active memberships.
        if ($activeOnly) {
            $enrolled = $this->courseMembershipIds()['enrolled'];
            if (! array_key_exists($course->id, $enrolled)) {
                return false;
            }
            $expiresAt = $enrolled[$course->id];
            return $expiresAt === null || \Carbon\Carbon::parse($expiresAt)->isFuture();
        }

        // Rare path — inactive rows aren't in the cache, fall back to DB.
        return $this->enrollments()->where('course_id', $course->id)->exists();
    }

    /**
     * Cached lookup of this user's course memberships. Returns:
     *
     *   [
     *     'taught'   => [1, 5, 8],                  // course IDs, role=teacher, active
     *     'enrolled' => [3 => null, 7 => '2027-01-01'],
     *                                               // course_id => expires_at ISO string
     *                                               // (or null for no expiry), role=student, active
     *   ]
     *
     * Consumed by teaches(), isEnrolledIn(), and visibleAnnouncements() —
     * turns 3-5 per-request queries into one on cache miss, zero on hit.
     * Busted by EnrollmentObserver whenever a membership changes.
     */
    public function courseMembershipIds(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            \App\Support\Cache\CacheKeys::userCourseMemberships($this->id),
            \App\Support\Cache\CacheKeys::TTL_COURSE_MEMBERSHIPS,
            function () {
                $rows = \App\Models\Enrollment::query()
                    ->where('user_id', $this->id)
                    ->where('is_active', true)
                    ->get(['course_id', 'role_on_course', 'expires_at']);

                $taught = [];
                $enrolled = [];
                foreach ($rows as $r) {
                    if ($r->role_on_course === \App\Models\Enrollment::ROLE_TEACHER) {
                        $taught[] = $r->course_id;
                    } elseif ($r->role_on_course === \App\Models\Enrollment::ROLE_STUDENT) {
                        $enrolled[$r->course_id] = $r->expires_at?->toIso8601String();
                    }
                }

                return ['taught' => $taught, 'enrolled' => $enrolled];
            }
        );
    }
}
