<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

    public static function current(): self
    {
        return Cache::remember(
            self::CACHE_KEY,
            3600,
            fn () => self::firstOrCreate(['id' => 1])
        );
    }

    public static function forgetCache(): void
    {
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
        return $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }
}
