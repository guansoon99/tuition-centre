<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BannerSlideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['banner.create', 'banner.edit']) ?? false;
    }

    public function rules(): array
    {
        $isCreate = $this->route('slide') === null;

        return [
            // Only jpeg/jpg/png/webp — no gif, bmp, svg. PublicFile
            // re-encodes to WebP on save regardless.
            'image' => [$isCreate ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:500'],
        ];
    }
}
