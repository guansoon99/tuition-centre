<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreSectionRequest;
use App\Http\Requests\Teacher\UpdateSectionRequest;
use App\Models\Course;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SectionController extends Controller
{
    public function create(Course $course): View
    {
        $this->authorize('create', [Section::class, $course]);

        return view('teacher.sections.create', ['course' => $course]);
    }

    public function store(StoreSectionRequest $request, Course $course): RedirectResponse
    {
        $type = $request->input('type', Section::TYPE_STANDARD);

        $imagePath = null;
        if ($type === Section::TYPE_IMAGE && $request->hasFile('image')) {
            $imagePath = $request->file('image')->store('sections', 'public');
        }

        $isPublished = $request->boolean('is_published', true);
        // Ticking "Published" at save time means "publish now" — clears any
        // pending schedule so it doesn't keep gating the section.
        $scheduledAt = $isPublished ? null : ($request->input('scheduled_at') ?: null);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'type' => $type,
            'target_date' => $type === Section::TYPE_COUNTDOWN ? $request->input('target_date') : null,
            'image_path' => $imagePath,
            'scheduled_at' => $scheduledAt,
            'sort_order' => $request->integer('sort_order') ?: ($course->sections()->max('sort_order') + 1),
            'is_published' => $isPublished,
        ]);

        return redirect()
            ->route('courses.show', $course)
            ->with('status', 'Section "'.$section->title.'" created.');
    }

    /**
     * Insert a placeholder section above or below an existing one (or at the
     * very top of the list), then redirect to its edit page so the user can
     * fill in title/description.
     */
    public function quickInsert(Request $request, Course $course): RedirectResponse
    {
        $this->authorize('create', [Section::class, $course]);

        $data = $request->validate([
            'position' => ['required', 'in:first,above,below'],
            'ref_section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        $section = DB::transaction(function () use ($course, $data) {
            $position = $data['position'];

            if ($position === 'first') {
                $target = 1;
            } else {
                $ref = Section::where('course_id', $course->id)
                    ->findOrFail($data['ref_section_id']);
                $target = $position === 'above'
                    ? $ref->sort_order
                    : $ref->sort_order + 1;
            }

            // Bump sort_order on all sections at-or-after the target.
            Section::where('course_id', $course->id)
                ->where('sort_order', '>=', $target)
                ->increment('sort_order');

            return Section::create([
                'course_id' => $course->id,
                'title' => 'Untitled section',
                'sort_order' => $target,
                'is_published' => false,
            ]);
        });

        return redirect()
            ->route('courses.edit', [$course, 'tab' => 'sections', 'open' => $section->id])
            ->with('status', 'Section inserted — fill in details and save.');
    }

    public function edit(Section $section): View
    {
        $this->authorize('update', $section);

        return view('teacher.sections.edit', ['section' => $section]);
    }

    public function update(UpdateSectionRequest $request, Section $section): RedirectResponse
    {
        $type = $request->input('type', Section::TYPE_STANDARD);

        $imagePath = $section->image_path;
        if ($type === Section::TYPE_IMAGE) {
            if ($request->hasFile('image')) {
                if ($section->image_path) {
                    Storage::disk('public')->delete($section->image_path);
                }
                $imagePath = $request->file('image')->store('sections', 'public');
            }
        } else {
            // Type changed away from image — clean up the file.
            if ($section->image_path) {
                Storage::disk('public')->delete($section->image_path);
            }
            $imagePath = null;
        }

        $isPublished = $request->boolean('is_published', true);
        // Ticking "Published" at save time means "publish now" — clears any
        // pending schedule so it doesn't keep gating the section.
        $scheduledAt = $isPublished ? null : ($request->input('scheduled_at') ?: null);

        $section->update([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'type' => $type,
            'target_date' => $type === Section::TYPE_COUNTDOWN ? $request->input('target_date') : null,
            'image_path' => $imagePath,
            'scheduled_at' => $scheduledAt,
            'sort_order' => $request->integer('sort_order'),
            'is_published' => $isPublished,
        ]);

        // Modal submits land back on the edit page with the modal closed.
        return redirect()
            ->route('courses.edit', [$section->course, 'tab' => 'sections'])
            ->with('status', 'Section updated.');
    }

    public function destroy(Section $section): RedirectResponse
    {
        $this->authorize('delete', $section);

        $course = $section->course;

        if ($section->image_path) {
            Storage::disk('public')->delete($section->image_path);
        }

        $section->delete();

        return redirect()
            ->route('courses.edit', [$course, 'tab' => 'sections'])
            ->with('status', 'Section deleted.');
    }
}
