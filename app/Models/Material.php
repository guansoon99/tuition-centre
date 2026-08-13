<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_PDF = 'pdf';
    public const TYPE_EXTERNAL_LINK = 'external_link';
    public const TYPE_TEXT = 'text';
    public const TYPE_PAGE = 'page';
    public const TYPE_COUNTDOWN = 'countdown';
    public const TYPE_ASSIGNMENT = 'assignment';

    /**
     * Submission limits. Single source of truth — the presign endpoint, the
     * register endpoint, the teacher form and the student UI all read these,
     * so a cap can never be enforced in one place and advertised in another.
     */
    public const DEFAULT_MAX_FILE_SIZE_MB = 50;

    public const MAX_FILE_SIZE_MB = 5120;   // R2's single-part PUT ceiling (5 GiB)

    public const DEFAULT_MAX_FILES = 5;

    /**
     * Accepted submission types. Checked twice: pinned into the presigned URL
     * as Content-Type, then verified against the stored object's real leading
     * bytes at registration — a client can declare anything it likes.
     */
    public const SUBMISSION_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    protected $fillable = [
        'section_id',
        'title',
        'type',
        'file_path',
        'external_url',
        'body',
        'target_date',
        'due_date',
        'max_file_size_mb',
        'max_files',
        'file_size_bytes',
        'sort_order',
        'is_published',
        'published_at',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'target_date' => 'datetime',
        'due_date' => 'datetime',
        'file_size_bytes' => 'integer',
        'max_file_size_mb' => 'integer',
        'max_files' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function isAssignment(): bool
    {
        return $this->type === self::TYPE_ASSIGNMENT;
    }

    public function isPastDue(): bool
    {
        return $this->due_date !== null && $this->due_date->isPast();
    }

    /** Per-file submission cap in bytes, falling back to the default. */
    public function maxFileSizeBytes(): int
    {
        return ($this->max_file_size_mb ?: self::DEFAULT_MAX_FILE_SIZE_MB) * 1024 * 1024;
    }

    /** How many files a student may attach to this assignment in total. */
    public function maxFiles(): int
    {
        return $this->max_files ?: self::DEFAULT_MAX_FILES;
    }
}
