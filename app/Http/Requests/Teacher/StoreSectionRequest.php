<?php

namespace App\Http\Requests\Teacher;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course instanceof Course
            && $this->user()->can('create', [\App\Models\Section::class, $course]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'in:standard,countdown,image,text'],
            'target_date' => ['nullable', 'date', 'required_if:type,countdown'],
            'image' => ['nullable', 'image', 'max:5120', 'required_if:type,image'],
            'scheduled_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
