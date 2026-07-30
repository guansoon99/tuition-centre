<?php

namespace App\Http\Requests\Admin;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['contact.create', 'contact.edit']) ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(Contact::TYPES))],
            'value' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:100'],
        ];
    }
}
