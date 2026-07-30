<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventRequest;
use App\Models\Event;
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
    public function events(Request $request): JsonResponse
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

        $events = $q->get()->map(fn (Event $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'start' => $e->date->format('Y-m-d'),
            'allDay' => true,
        ]);

        return response()->json($events);
    }

    public function store(EventRequest $request): RedirectResponse|JsonResponse
    {
        $event = Event::create([
            'title' => $request->input('title'),
            'date' => $request->input('date'),
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
