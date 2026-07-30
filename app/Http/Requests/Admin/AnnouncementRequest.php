<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

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
            // Audience is 'all' (everyone) or any role name currently in the
            // system. Course scoping is optional and orthogonal.
            $allowed = array_merge(['all'], Role::pluck('name')->all());
            $rules['audience'] = ['required', Rule::in($allowed)];
            $rules['course_id'] = ['nullable', 'integer', 'exists:courses,id'];
        }

        return $rules;
    }
}
