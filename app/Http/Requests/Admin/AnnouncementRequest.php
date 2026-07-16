<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['announcements.create', 'announcements.edit']) ?? false;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');

        $rules = [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i', 'after_or_equal:starts_at'],
        ];

        if (! $isUpdate) {
            // Audience + course only apply on first send; can't be changed afterwards
            // because recipient rows have already been fanned out.
            $rules['audience'] = ['required', Rule::in(['all', 'students', 'teachers'])];
            $rules['course_id'] = ['nullable', 'integer', 'exists:courses,id'];
        }

        return $rules;
    }
}
