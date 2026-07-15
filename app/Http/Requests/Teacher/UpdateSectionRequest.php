<?php

namespace App\Http\Requests\Teacher;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section instanceof Section && $this->user()->can('update', $section);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
