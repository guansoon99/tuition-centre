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

    /**
     * Light tint of each color, used when display_style='background' to
     * paint the whole day cell without overwhelming it. Tailwind's 100-shade
     * for each colour reads well.
     */
    public const COLOR_TINTS = [
        'blue'   => '#dbeafe',
        'green'  => '#d1fae5',
        'red'    => '#fee2e2',
        'amber'  => '#fef3c7',
        'purple' => '#ede9fe',
        'teal'   => '#ccfbf1',
        'pink'   => '#fce7f3',
        'slate'  => '#e2e8f0',
    ];

    /**
     * Darker shade of each color for the injected text label — needs to
     * stay readable on the light tint background. Tailwind 700-shade.
     */
    public const COLOR_TEXTS = [
        'blue'   => '#1d4ed8',
        'green'  => '#047857',
        'red'    => '#b91c1c',
        'amber'  => '#b45309',
        'purple' => '#6d28d9',
        'teal'   => '#0f766e',
        'pink'   => '#be185d',
        'slate'  => '#334155',
    ];

    public const COLOR_DEFAULT = 'blue';

    public const STYLE_PILL = 'pill';
    public const STYLE_BACKGROUND = 'background';
    public const STYLES = [self::STYLE_PILL, self::STYLE_BACKGROUND];
    public const STYLE_DEFAULT = self::STYLE_PILL;

    protected $fillable = [
        'title',
        'date',
        'color',
        'display_style',
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

    public function colorTintHex(): string
    {
        return self::COLOR_TINTS[$this->color] ?? self::COLOR_TINTS[self::COLOR_DEFAULT];
    }

    public function colorTextHex(): string
    {
        return self::COLOR_TEXTS[$this->color] ?? self::COLOR_TEXTS[self::COLOR_DEFAULT];
    }
}
