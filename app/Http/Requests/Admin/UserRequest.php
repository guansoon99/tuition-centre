<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $isCreate = $this->isMethod('POST') && ! $userId;

        return [
            'username' => ['required', 'string', 'max:64',
                Rule::unique('users', 'username')->ignore($userId)],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'ic_number' => ['nullable', 'string', 'max:32'],
            'candidate_number' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'is_active' => ['nullable', 'boolean'],
            'password' => $isCreate
                ? ['required', 'confirmed', 'string']
                : ['nullable', 'confirmed', 'string'],
        ];
    }
}
