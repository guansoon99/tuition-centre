<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventRequest;
use App\Models\Event;
use App\Support\HolidayProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(): View
    {
        return view('admin.calendar.index');
    }

    /**
     * FullCalendar JSON feed. Called with ?start=YYYY-MM-DD&end=YYYY-MM-DD by
     * the calendar for each visible month; we return only events in range so
     * we don't ship every event ever created on every render.
     */
    public function events(Request $request, HolidayProvider $holidays): JsonResponse
    {
        $start = $request->query('start');
        $end = $request->query('end');

        $q = Event::query();
        if ($start) {
            $q->where('date', '>=', substr($start, 0, 10));
        }
        if ($end) {
            $q->where('date', '<=', substr($end, 0, 10));
        }

        $userEvents = $q->get()->map(function (Event $e) {
            $isBackground = $e->display_style === Event::STYLE_BACKGROUND;
            return [
                'id' => $e->id,
                'title' => $e->title,
                'start' => $e->date->format('Y-m-d'),
                'allDay' => true,
                // 'background' tints the whole day cell (like the holidays);
                // omitted → normal pill.
                'display' => $isBackground ? 'background' : 'auto',
                // For pills we use the full color; for backgrounds we send
                // the light tint so the cell stays readable underneath.
                'backgroundColor' => $isBackground ? $e->colorTintHex() : $e->colorHex(),
                'borderColor' => $isBackground ? $e->colorTintHex() : $e->colorHex(),
                'extendedProps' => [
                    'color' => $e->color,
                    'displayStyle' => $e->display_style,
                    'textHex' => $e->colorTextHex(),
                ],
            ];
        })->all();

        // Malaysia public holidays from the Google iCal feed. Rendered as
        // background events so the whole day cell is tinted (matches printed
        // Malaysian calendars). The frontend's eventDidMount hook also
        // writes the holiday name directly into the cell as a small red
        // label — no pill, no click target.
        //
        // Uses the same 'red' palette entries as user events, so a user who
        // creates their own red+highlight event gets a visually identical
        // result.
        $holidayTint = Event::COLOR_TINTS['red'];
        $holidayText = Event::COLOR_TEXTS['red'];
        $holidayEvents = [];
        if ($start && $end) {
            foreach ($holidays->forRange(substr($start, 0, 10), substr($end, 0, 10)) as $h) {
                $holidayEvents[] = [
                    'id' => 'holiday-'.$h['date'],
                    'title' => $h['name'],
                    'start' => $h['date'],
                    'allDay' => true,
                    'display' => 'background',
                    'backgroundColor' => $holidayTint,
                    'extendedProps' => [
                        'isHoliday' => true,
                        'color' => 'red',
                        'textHex' => $holidayText,
                    ],
                ];
            }
        }

        return response()->json(array_merge($userEvents, $holidayEvents));
    }

    public function store(EventRequest $request): RedirectResponse|JsonResponse
    {
        $event = Event::create([
            'title' => $request->input('title'),
            'date' => $request->input('date'),
            'color' => $request->input('color', Event::COLOR_DEFAULT),
            'display_style' => $request->input('display_style', Event::STYLE_DEFAULT),
            'created_by_user_id' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'id' => $event->id]);
        }

        return redirect()->route('calendar.index')->with('status', 'Event added.');
    }

    public function update(EventRequest $request, Event $event): RedirectResponse|JsonResponse
    {
        $event->update([
            'title' => $request->input('title'),
            'date' => $request->input('date'),
            'color' => $request->input('color', $event->color),
            'display_style' => $request->input('display_style', $event->display_style),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('calendar.index')->with('status', 'Event updated.');
    }

    public function destroy(Event $event, Request $request): RedirectResponse|JsonResponse
    {
        $event->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('calendar.index')->with('status', 'Event deleted.');
    }
}
