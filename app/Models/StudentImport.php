<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A student import run. Created queued by the controller, driven through
 * running → done|failed by the ImportStudents job, read by the page and its
 * status endpoint.
 */
class StudentImport extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const MODE_SKIP = 'skip';
    public const MODE_ALL = 'all';

    protected $fillable = [
        'user_id',
        'original_name',
        'stored_path',
        'mode',
        'status',
        'total',
        'done',
        'result',
        'credentials_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'result' => 'array',
        'total' => 'integer',
        'done' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Imports of this admin that are still queued or running. */
    public static function activeFor(int $userId): Builder
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /**
     * Live progress lives in the cache, not on this row. The importer runs
     * inside one transaction, and a row update from within it would sit
     * unseen by the polling request until the whole import committed — a
     * bar stuck at 0% until it jumps to 100%.
     */
    public function recordProgress(int $done, int $total): void
    {
        Cache::put($this->progressKey(), ['done' => $done, 'total' => $total], now()->addHours(6));
    }

    /** @return array{done:int,total:int} */
    public function progress(): array
    {
        $p = Cache::get($this->progressKey());

        if (! is_array($p)) {
            $p = ['done' => $this->done, 'total' => $this->total];
        }

        return ['done' => (int) $p['done'], 'total' => (int) $p['total']];
    }

    /** What the status endpoint answers and the page's bar is drawn from. */
    public function progressPayload(): array
    {
        $p = $this->progress();
        $total = max($p['total'], $this->total);
        $done = $this->status === self::STATUS_DONE ? $total : min($p['done'], $total);
        $result = $this->result ?? [];

        return [
            'status' => $this->status,
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            'created' => count($result['ok'] ?? []),
            'skipped' => count($result['skipped'] ?? []),
            'errors' => count($result['errors'] ?? []),
            'error' => $this->error,
        ];
    }

    private function progressKey(): string
    {
        return "student-import:{$this->id}:progress";
    }
}
