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

        $userEvents = $q->get()->map(fn (Event $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'start' => $e->date->format('Y-m-d'),
            'allDay' => true,
            // FullCalendar reads these per-event and applies them directly
            // to the rendered pill. Slug lives on the row; hex is resolved
            // here so palette tweaks are one-file changes.
            'backgroundColor' => $e->colorHex(),
            'borderColor' => $e->colorHex(),
            'extendedProps' => ['color' => $e->color],
        ])->all();

        // Malaysia public holidays from the Google iCal feed. Only include
        // those in the visible range so we don't ship the whole feed on
        // every calendar hit. `id` gets a prefix so it can't collide with
        // numeric event IDs and the frontend can distinguish read-only
        // holidays from admin-editable events.
        $holidayEvents = [];
        if ($start && $end) {
            foreach ($holidays->forRange(substr($start, 0, 10), substr($end, 0, 10)) as $h) {
                $holidayEvents[] = [
                    'id' => 'holiday-'.$h['date'],
                    'title' => $h['name'],
                    'start' => $h['date'],
                    'allDay' => true,
                    'backgroundColor' => '#ef4444', // red
                    'borderColor' => '#ef4444',
                    'editable' => false,
                    'extendedProps' => ['isHoliday' => true, 'color' => 'red'],
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
