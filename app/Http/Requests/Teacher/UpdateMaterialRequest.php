<?php

namespace App\Http\Requests\Teacher;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material instanceof Material && $this->user()->can('update', $material);
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                Material::TYPE_PDF,
                Material::TYPE_EXTERNAL_LINK,
                Material::TYPE_TEXT,
                Material::TYPE_PAGE,
                Material::TYPE_COUNTDOWN,
                Material::TYPE_ASSIGNMENT,
            ])],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
            'external_url' => ['nullable', 'required_if:type,external_link', 'url'],
            'body' => ['nullable', 'required_if:type,text', 'required_if:type,page', 'string'],
            'target_date' => ['nullable', 'required_if:type,countdown', 'date'],
            'due_date' => ['nullable', 'date'],
            'max_file_size_mb' => ['nullable', 'integer', 'min:1', 'max:'.\App\Models\Material::MAX_FILE_SIZE_MB],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
