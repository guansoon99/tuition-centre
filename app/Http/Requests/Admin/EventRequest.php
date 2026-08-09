<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['calendar.create', 'calendar.edit']) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'],
            'color' => ['nullable', Rule::in(array_keys(Event::COLORS))],
        ];
    }
}
