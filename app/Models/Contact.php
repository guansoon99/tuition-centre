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

    public const TYPES = [
        self::TYPE_PHONE => 'Phone',
        self::TYPE_WHATSAPP => 'WhatsApp',
        self::TYPE_TELEGRAM => 'Telegram',
    ];

    protected $fillable = [
        'type',
        'value',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
                default => null,
            };
        });
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn () => self::TYPES[$this->type] ?? ucfirst($this->type));
    }
}
