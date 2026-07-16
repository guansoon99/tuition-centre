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
            'image' => [$isCreate ? 'required' : 'nullable', 'image', 'max:5120'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
