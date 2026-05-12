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
    public function visibleNotifications()
    {
        $now = now()->format('Y-m-d H:i:s');

        return $this->notifications()
            ->where(function ($q) use ($now) {
                $q->whereNull('data->starts_at')
                  ->orWhere('data->starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('data->ends_at')
                  ->orWhere('data->ends_at', '>=', $now);
            });
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
