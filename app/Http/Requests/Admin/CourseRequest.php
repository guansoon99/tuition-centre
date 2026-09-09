<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Store (POST /courses) takes courses.create; update (PATCH
        // /courses/{slug}) takes courses.manage_details. Admins pass both
        // through Gate::before.
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->can($this->isMethod('POST') ? 'courses.create' : 'courses.manage_details');
    }

    public function rules(): array
    {
        $courseId = $this->route('course')?->id;

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('courses', 'code')->ignore($courseId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // Only jpeg/jpg/png/webp — no gif, bmp, svg. PublicFile
            // re-encodes to WebP on save regardless.
            'banner_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
