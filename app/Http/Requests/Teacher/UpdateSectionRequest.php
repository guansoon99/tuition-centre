<?php

namespace App\Http\Requests\Teacher;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $section = $this->route('section');

        if (! $section instanceof Section) {
            return false;
        }

        // inspect() rather than can(): a refusal from the policy carries the
        // "not a teacher on this course" message, and a bool would drop it.
        return Gate::inspect('update', $section);
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
