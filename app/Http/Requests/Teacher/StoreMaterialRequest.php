<?php

namespace App\Http\Requests\Teacher;

use App\Models\Material;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $section = $this->route('section');

        if (! $section instanceof Section) {
            return false;
        }

        // inspect() rather than can(): a refusal from the policy carries the
        // "not a teacher on this course" message, and a bool would drop it.
        return Gate::inspect('create', [Material::class, $section]);
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                Material::TYPE_PDF,
                Material::TYPE_EXTERNAL_LINK,
                Material::TYPE_MEDIA,
                Material::TYPE_ANNOUNCEMENT,
                Material::TYPE_PAGE,
                Material::TYPE_COUNTDOWN,
                Material::TYPE_ASSIGNMENT,
            ])],
            'file' => ['nullable', 'required_if:type,pdf', 'file', 'mimes:pdf', 'max:51200'],
            'external_url' => ['nullable', 'required_if:type,external_link', 'url:http,https'],
            'body' => ['nullable', 'required_if:type,'.Material::TYPE_MEDIA, 'required_if:type,'.Material::TYPE_ANNOUNCEMENT, 'required_if:type,'.Material::TYPE_PAGE, 'string'],
            'target_date' => ['nullable', 'required_if:type,countdown', 'date'],
            'countdown_theme' => ['nullable', 'string', Rule::in(array_keys(Material::COUNTDOWN_THEMES))],
            'due_date' => ['nullable', 'date'],
            'max_file_size_mb' => ['nullable', 'integer', 'min:1', 'max:'.\App\Models\Material::MAX_FILE_SIZE_MB],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
