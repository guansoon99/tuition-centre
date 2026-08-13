<?php

namespace App\Http\Requests\Admin;

use App\Models\Announcement;
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
        $type = $this->input('type');

        /** @var Announcement|null $existing */
        $existing = $this->route('announcement');

        $allowedAudience = array_merge(['all'], Role::pluck('name')->all());

        $rules = [
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Announcement::TYPES))],
            'audience' => ['required', Rule::in($allowedAudience)],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i', 'after_or_equal:starts_at'],
        ];

        // Body — required whenever the (new) type is text.
        $rules['body'] = [
            Rule::requiredIf($type === Announcement::TYPE_TEXT),
            'nullable', 'string', 'max:2000',
        ];

        // Image — required when the (new) type is image AND we don't already
        // have an image on the existing announcement (i.e. creating or
        // switching from text → image).
        $imageRequired = $type === Announcement::TYPE_IMAGE
            && (! $isUpdate || ! $existing?->image_path);
        // Only jpeg/jpg/png/webp — no gif, bmp, svg. PublicFile
        // re-encodes to WebP on save regardless.
        $rules['image'] = [
            Rule::when($imageRequired, ['required'], ['nullable']),
            'image',
            'mimes:jpeg,jpg,png,webp',
            'max:5120',
        ];

        return $rules;
    }
}
