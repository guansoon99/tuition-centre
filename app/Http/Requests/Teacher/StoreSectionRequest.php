<?php

namespace App\Http\Requests\Teacher;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $course = $this->route('course');

        if (! $course instanceof Course) {
            return false;
        }

        // inspect() rather than can(): a refusal from the policy carries the
        // "not a teacher on this course" message, and a bool would drop it.
        return Gate::inspect('create', [\App\Models\Section::class, $course]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'never_collapses' => ['nullable', 'boolean'],
        ];
    }
}
