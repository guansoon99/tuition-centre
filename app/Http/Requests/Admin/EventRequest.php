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
            'display_style' => [
                'nullable',
                Rule::in(Event::STYLES),
                $this->onlyOneHighlightPerDay(),
            ],
        ];
    }

    /**
     * A day may carry any number of bars, but only one highlight.
     *
     * Two highlights on one date are two background events tinting the same
     * cell — the second just paints over the first, so the calendar shows one
     * colour and the other event is invisible but still there. Bars stack
     * visibly and are unrestricted.
     */
    private function onlyOneHighlightPerDay(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== Event::STYLE_BACKGROUND) {
                return;
            }

            $date = $this->input('date');
            if (! $date) {
                return; // The date rule will report this instead.
            }

            // whereDate, not where: the column is cast to a date and stored
            // with a 00:00:00 time, so an equality match on 'Y-m-d' compares
            // against '2026-09-15 00:00:00' and silently never matches.
            $clash = Event::whereDate('date', $date)
                ->where('display_style', Event::STYLE_BACKGROUND);

            // When editing, the event being saved is not its own clash.
            if ($current = $this->route('event')) {
                $clash->whereKeyNot($current->getKey());
            }

            if ($clash->exists()) {
                $fail('That date already has a highlight — a day can only have one.');
            }
        };
    }
}
