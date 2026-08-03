<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use HasFactory, SoftDeletes;

    public const ROLE_STUDENT = 'student';
    public const ROLE_TEACHER = 'teacher';

    protected $fillable = [
        'user_id',
        'course_id',
        'role_on_course',
        'enrolled_at',
        'expires_at',
        'is_active',
        'last_accessed_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function scopeStudents(Builder $query): Builder
    {
        return $query->where('role_on_course', self::ROLE_STUDENT);
    }

    public function scopeTeachers(Builder $query): Builder
    {
        return $query->where('role_on_course', self::ROLE_TEACHER);
    }
}
