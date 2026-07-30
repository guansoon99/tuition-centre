<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::with('course')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.create', [
            'courses' => $this->coursesForSelect(),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $type = $request->input('type');

        $data = [
            'title' => $request->input('title'),
            'type' => $type,
            'body' => $type === Announcement::TYPE_TEXT ? $request->input('body') : '',
            'audience' => $request->input('audience'),
            'course_id' => $request->integer('course_id') ?: null,
            'starts_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('starts_at')),
            'ends_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('ends_at')),
            // Auto-append to the end of the existing order.
            'sort_order' => (int) Announcement::max('sort_order') + 1,
            'created_by_user_id' => $request->user()->id,
        ];

        if ($type === Announcement::TYPE_IMAGE && $request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('announcement-images', 'public');
        }

        Announcement::create($data);

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement published.');
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', [
            'announcement' => $announcement->load('course'),
            'courses' => $this->coursesForSelect(),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $newType = $request->input('type');

        $data = [
            'title' => $request->input('title'),
            'type' => $newType,
            'audience' => $request->input('audience'),
            'course_id' => $request->integer('course_id') ?: null,
            'starts_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('starts_at')),
            'ends_at' => Carbon::createFromFormat('Y-m-d H:i', $request->input('ends_at')),
        ];

        if ($newType === Announcement::TYPE_TEXT) {
            $data['body'] = $request->input('body');
            // Switching from image → text: delete the old image file and clear the path.
            if ($announcement->type === Announcement::TYPE_IMAGE && $announcement->image_path) {
                Storage::disk('public')->delete($announcement->image_path);
                $data['image_path'] = null;
            }
        } else { // TYPE_IMAGE
            $data['body'] = '';
            if ($request->hasFile('image')) {
                // New/replacement image: delete any old file first.
                if ($announcement->image_path) {
                    Storage::disk('public')->delete($announcement->image_path);
                }
                $data['image_path'] = $request->file('image')->store('announcement-images', 'public');
            }
            // Otherwise keep the existing image_path (validation ensures one
            // exists when switching text → image).
        }

        $announcement->update($data);

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        if ($announcement->image_path) {
            Storage::disk('public')->delete($announcement->image_path);
        }
        $announcement->delete();

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement deleted.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $valid = Announcement::whereIn('id', $data['ids'])->pluck('id')->all();

        DB::transaction(function () use ($data, $valid) {
            $order = 1;
            foreach ($data['ids'] as $id) {
                if (! in_array((int) $id, $valid, true)) {
                    continue;
                }
                Announcement::where('id', $id)->update(['sort_order' => $order++]);
            }
        });

        return response()->json(['ok' => true, 'count' => count($valid)]);
    }

    private function coursesForSelect()
    {
        return Course::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
