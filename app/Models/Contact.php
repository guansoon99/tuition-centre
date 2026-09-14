<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    public const TYPE_PHONE = 'phone';
    public const TYPE_WHATSAPP = 'whatsapp';
    public const TYPE_TELEGRAM = 'telegram';
    public const TYPE_FACEBOOK = 'facebook';
    public const TYPE_XHS = 'xhs';

    public const TYPES = [
        self::TYPE_PHONE => 'Phone',
        self::TYPE_WHATSAPP => 'WhatsApp',
        self::TYPE_TELEGRAM => 'Telegram',
        self::TYPE_FACEBOOK => 'Facebook',
        self::TYPE_XHS => 'Xiaohongshu (XHS)',
    ];

    protected $fillable = [
        'type',
        'value',
        'label',
        'icon_path',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The active contacts in display order, cached. Shared by the floating
     * contact buttons and the public footer; ContactController forgets
     * 'public:contacts' on any change.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,self>
     */
    public static function activeCached(): \Illuminate\Database\Eloquent\Collection
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'public:contacts',
            3600,
            fn () => static::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Convert the stored value into a clickable URL. Phone → tel:, WhatsApp →
     * wa.me/, Telegram → t.me/. Returns null if no sensible URL applies.
     */
    protected function url(): Attribute
    {
        return Attribute::get(function () {
            $digits = preg_replace('/\D/', '', (string) $this->value);

            return match ($this->type) {
                self::TYPE_PHONE => $digits ? 'tel:+'.$digits : null,
                self::TYPE_WHATSAPP => $digits ? 'https://wa.me/'.$digits : null,
                self::TYPE_TELEGRAM => 'https://t.me/'.ltrim($this->value, '@'),
                // A full link is used as given; a bare page name or profile
                // id is put on the site's profile URL.
                self::TYPE_FACEBOOK => self::linkOr($this->value, 'https://www.facebook.com/'),
                self::TYPE_XHS => self::linkOr($this->value, 'https://www.xiaohongshu.com/user/profile/'),
                default => null,
            };
        });
    }

    private static function linkOr(?string $value, string $base): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return preg_match('#^https?://#i', $value) ? $value : $base.ltrim($value, '@/');
    }

    /** The uploaded icon's URL, or null to use the built-in glyph. */
    protected function iconUrl(): Attribute
    {
        return Attribute::get(fn () => \App\Support\PublicFile::url($this->icon_path));
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn () => self::TYPES[$this->type] ?? ucfirst($this->type));
    }
}
