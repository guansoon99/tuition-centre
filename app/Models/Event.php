<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    /**
     * Color palette for calendar events. Keys are what we store on the
     * row (short slugs); values are the hex we send to FullCalendar.
     * Change hex freely — the DB only sees the slug.
     */
    public const COLORS = [
        'blue'   => '#3b82f6',
        'green'  => '#10b981',
        'red'    => '#ef4444',
        'amber'  => '#f59e0b',
        'purple' => '#8b5cf6',
        'teal'   => '#14b8a6',
        'pink'   => '#ec4899',
        'slate'  => '#64748b',
    ];

    public const COLOR_DEFAULT = 'blue';

    protected $fillable = [
        'title',
        'date',
        'color',
        'created_by_user_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Resolve the row's color slug to a hex value; falls back to the
     * default if a stale slug is somehow stored.
     */
    public function colorHex(): string
    {
        return self::COLORS[$this->color] ?? self::COLORS[self::COLOR_DEFAULT];
    }
}
