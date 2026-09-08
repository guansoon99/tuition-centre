<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSettings extends Model
{
    public const CACHE_KEY = 'site:settings';

    protected $table = 'site_settings';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'logo_path',
        'contact_phone',
        'contact_address',
        'contact_hours',
        'students_can_change_password',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
        'students_can_change_password' => 'boolean',
    ];

    /** Container key for the per-request memo. See AppServiceProvider. */
    public const CONTAINER_KEY = 'site.settings.current';

    /**
     * The active settings row.
     *
     * Called ~5x per page render (title, meta description, favicon, footer,
     * brand component). Each call would otherwise re-read and unserialize the
     * cached model — ~0.2ms a pop. A scoped container binding collapses that
     * to one read.
     *
     * Deliberately NOT a `static` property: this returns a live Eloquent
     * model that callers write through (SettingsController::update does
     * `current()->update(...)`). A plain static would outlive the request and
     * hand a stale model to the next job in a queue worker — and to the next
     * test after the database rolls back. `scoped()` is reset on both
     * boundaries, so the memo can never outlive the state it was built from.
     */
    public static function current(): self
    {
        return app(self::CONTAINER_KEY);
    }

    /**
     * The one row this table holds, created on first use.
     *
     * Not firstOrCreate(['id' => 1]): `id` is not fillable, so that form
     * silently dropped the id on create and the row got whatever the
     * auto-increment handed out. SQLite winds its counter back when a test
     * transaction rolls back, so the row always came out as 1; MySQL does
     * not, so on the MySQL test leg every lookup for id 1 missed and created
     * a fresh blank row per call — the controller saving into one row, the
     * next read returning another. In production the same would follow from
     * a deleted row 1: every save landing in a row nobody reads.
     */
    public static function row(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::forceCreate(['id' => 1]);
    }

    public static function forgetCache(): void
    {
        // Drop the per-request memo as well, so a save is visible immediately
        // rather than at the end of the current request.
        app()->forgetInstance(self::CONTAINER_KEY);
        Cache::forget(self::CACHE_KEY);
    }

    public function displayName(): string
    {
        return $this->name ?: config('app.name');
    }

    public function metaDescription(): string
    {
        return $this->description ?: 'STPM, SPM and pre-university tuition.';
    }

    public function logoUrl(): ?string
    {
        return \App\Support\PublicFile::url($this->logo_path);
    }
}
