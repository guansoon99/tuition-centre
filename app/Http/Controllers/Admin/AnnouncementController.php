<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::with('course')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.create', [
            'courses' => $this->coursesForSelect(),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        Announcement::create([
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'audience' => $request->input('audience'),
            'course_id' => $request->integer('course_id') ?: null,
            'starts_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('starts_at')),
            'ends_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('ends_at')),
            'created_by_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement published.');
    }

    public function show(Announcement $announcement): View
    {
        return view('admin.announcements.show', [
            'announcement' => $announcement->load('course'),
        ]);
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', [
            'announcement' => $announcement->load('course'),
        ]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update([
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'starts_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('starts_at')),
            'ends_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('ends_at')),
        ]);

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement deleted.');
    }

    private function coursesForSelect()
    {
        return Course::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
