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
