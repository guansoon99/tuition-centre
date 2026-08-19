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
     * Accepted submission types, extension => canonical MIME.
     *
     * The extension is not decoration: for Office documents it is the only
     * thing that can tell Word from PowerPoint. See resolveSubmissionMime().
     */
    public const SUBMISSION_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * The same list as MIME types, for the presign whitelist and the file
     * picker's accept attribute. Written out rather than derived because a
     * class constant cannot call a function; SubmissionFileTypeTest asserts
     * the two never drift apart.
     */
    public const SUBMISSION_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * What a content sniff may legitimately return for an Office file it
     * cannot pin down, and the extensions each may stand in for.
     *
     * Both cases are limitations of libmagic, verified against real files
     * rather than assumed:
     *
     *  - OOXML (.docx/.pptx) is a ZIP. libmagic identifies it by finding the
     *    "word/" or "ppt/" entry near the start, and gives up beyond a fixed
     *    distance — a .docx with ~5KB of anything before word/document.xml
     *    reports application/zip even when the WHOLE file is read, so no
     *    larger sniff window fixes this.
     *  - Legacy .doc/.ppt are OLE2 compound files. Both report
     *    application/CDFV2; the format carries nothing that separates Word
     *    from PowerPoint at this level.
     *
     * So the sniff proves the container, and the extension picks the label.
     * The extension is ours, not the student's: it comes from the whitelisted
     * Content-Type they declared at presign, and the key is bound to their own
     * prefix. The residual case is a student storing a renamed .zip as their
     * own submission, which is theirs to get wrong.
     */
    private const CONTAINER_MIME_TYPES = [
        'application/zip' => ['docx', 'pptx'],
        'application/CDFV2' => ['doc', 'ppt'],
        'application/x-ole-storage' => ['doc', 'ppt'],
        'application/vnd.ms-office' => ['doc', 'ppt'],
    ];

    /**
     * Decide what a submitted file really is, or null to reject it.
     *
     * $sniffed is what the bytes say, $extension what the file is called.
     * A sniff that names an accepted type is taken at its word; otherwise the
     * bytes must at least be the right kind of container for the extension.
     */
    public static function resolveSubmissionMime(?string $sniffed, string $extension): ?string
    {
        $ext = strtolower(ltrim($extension, '.'));

        if (! isset(self::SUBMISSION_TYPES[$ext])) {
            return null;
        }

        if ($sniffed !== null && in_array($sniffed, self::SUBMISSION_MIME_TYPES, true)) {
            return $sniffed;
        }

        $standsInFor = self::CONTAINER_MIME_TYPES[$sniffed] ?? null;

        return ($standsInFor !== null && in_array($ext, $standsInFor, true))
            ? self::SUBMISSION_TYPES[$ext]
            : null;
    }

    /** MIME types the sniff may return for something we will accept. */
    public static function sniffableSubmissionMimeTypes(): array
    {
        return array_values(array_unique(array_merge(
            self::SUBMISSION_MIME_TYPES,
            array_keys(self::CONTAINER_MIME_TYPES),
        )));
    }

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
