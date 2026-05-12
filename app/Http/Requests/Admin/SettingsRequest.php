<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_address' => ['nullable', 'string', 'max:500'],
            'contact_hours' => ['nullable', 'string', 'max:255'],
            'students_can_change_password' => ['nullable', 'boolean'],
        ];
    }
}
