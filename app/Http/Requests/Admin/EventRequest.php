<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
