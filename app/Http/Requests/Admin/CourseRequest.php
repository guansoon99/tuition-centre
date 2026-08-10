<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Store (POST /courses) still needs admin — create isn't a granular
        // perm. Update (PATCH /courses/{slug}) allows courses.manage_details.
        $user = $this->user();
        if (! $user) {
            return false;
        }
        if ($this->isMethod('POST')) {
            return $user->hasRole('admin');
        }
        return $user->can('courses.manage_details');
    }

    public function rules(): array
    {
        $courseId = $this->route('course')?->id;

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('courses', 'code')->ignore($courseId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Only jpeg/jpg/png/webp — no gif, bmp, svg. StoredFile
            // re-encodes to WebP on save regardless.
            'banner_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
