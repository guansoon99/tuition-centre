<?php

namespace App\Http\Requests\Teacher;

use App\Models\Material;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section instanceof Section
            && $this->user()->can('create', [Material::class, $section]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                Material::TYPE_PDF,
                Material::TYPE_EXTERNAL_LINK,
                Material::TYPE_VIDEO_LINK,
                Material::TYPE_TEXT,
                Material::TYPE_COUNTDOWN,
            ])],
            'file' => ['nullable', 'required_if:type,pdf', 'file', 'mimes:pdf', 'max:51200'],
            'external_url' => ['nullable', 'required_if:type,external_link', 'required_if:type,video_link', 'url'],
            'body' => ['nullable', 'required_if:type,text', 'string'],
            'target_date' => ['nullable', 'required_if:type,countdown', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
