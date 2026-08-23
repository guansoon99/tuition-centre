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
            // Optional, and `users.email` is nullable but UNIQUE — so a blank
            // box has to arrive as NULL, never ''. Many rows may hold NULL;
            // two holding '' collide, and it would be the *second* blank save
            // that dies on the constraint. Nothing here does that conversion:
            // the global TrimStrings + ConvertEmptyStringsToNull middleware
            // (app/Http/Kernel.php) already turns '' and '   ' into null
            // before validation runs. Removing either from the stack breaks
            // this field — UserEmailFieldTest is what would catch it.
            //
            // The unique rule sees soft-deleted rows, matching the database
            // constraint: a trashed user keeps their email, so it stays taken
            // until the account is restored or purged.
            'email' => ['nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId)],
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
