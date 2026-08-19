<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_id',
        'user_id',
        'submitted_at',
        'last_modified_at',
        'grade',
        'comment',
        'graded_at',
        'graded_by_user_id',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'last_modified_at' => 'datetime',
        'graded_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by_user_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    /** Files the teacher returned. Separate from files() on purpose. */
    public function feedbackFiles(): HasMany
    {
        return $this->hasMany(FeedbackFile::class);
    }

    /** Feedback is only offered on work that was actually handed in. */
    public function hasSubmittedWork(): bool
    {
        return $this->files()->exists();
    }

    public function isGraded(): bool
    {
        return $this->graded_at !== null;
    }

    /**
     * Record that the student changed their submission.
     *
     * Adding a file and removing one are both modifications, so both call
     * this. Deliberately separate from updated_at, which also moves when a
     * teacher grades — that is the teacher touching the row, not the student
     * touching their work.
     */
    public function markModified(): void
    {
        $this->forceFill(['last_modified_at' => now()])->save();
    }
}
