<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\Course;
use App\Support\PrivateFile;
use App\Support\PrivateImage;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Null means unbounded on that side — createFromFormat would
            // throw on an empty string rather than give us that.
            'starts_at' => $this->parseMoment($request->input('starts_at')),
            'ends_at' => $this->parseMoment($request->input('ends_at')),
            // Auto-append to the end of the existing order.
            'sort_order' => (int) Announcement::max('sort_order') + 1,
            'created_by_user_id' => $request->user()->id,
        ];

        if ($type === Announcement::TYPE_IMAGE && $request->hasFile('image')) {
            $data['image_path'] = PrivateImage::store($request->file('image'), 'announcement-images');
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
            // Null means unbounded on that side — createFromFormat would
            // throw on an empty string rather than give us that.
            'starts_at' => $this->parseMoment($request->input('starts_at')),
            'ends_at' => $this->parseMoment($request->input('ends_at')),
        ];

        // Any superseded file is noted here and deleted only once the row has
        // been saved — see the note in SettingsController::update. Deleting
        // first destroys the existing image if the save never happens.
        $replaced = null;

        if ($newType === Announcement::TYPE_TEXT) {
            $data['body'] = $request->input('body');
            // Switching from image → text: the old image is no longer used.
            if ($announcement->type === Announcement::TYPE_IMAGE && $announcement->image_path) {
                $replaced = $announcement->image_path;
                $data['image_path'] = null;
            }
        } else { // TYPE_IMAGE
            $data['body'] = '';
            if ($request->hasFile('image')) {
                $replaced = $announcement->image_path;
                $data['image_path'] = PrivateImage::store($request->file('image'), 'announcement-images');
            }
            // Otherwise keep the existing image_path (validation ensures one
            // exists when switching text → image).
        }

        $announcement->update($data);

        PrivateFile::forget($replaced);

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        if ($announcement->image_path) {
            PrivateFile::forget($announcement->image_path);
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

    /**
     * A datetime from the form, or null when the field was left blank.
     *
     * Blank is meaningful here: no start means the announcement is live
     * immediately, no end means it never expires.
     */
    private function parseMoment(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::createFromFormat('Y-m-d H:i', $value);
    }
}
