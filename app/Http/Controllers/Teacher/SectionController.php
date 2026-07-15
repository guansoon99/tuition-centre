<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreSectionRequest;
use App\Http\Requests\Teacher\UpdateSectionRequest;
use App\Models\Course;
use App\Models\Section;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
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
        $isPublished = $request->boolean('is_published', true);
        // Ticking "Published" at save time means "publish now" — clears any
        // pending schedule so it doesn't keep gating the section.
        $scheduledAt = $isPublished ? null : ($request->input('scheduled_at') ?: null);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => $request->input('title'),
            'type' => Section::TYPE_STANDARD,
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
            ->route('courses.edit', [$course, 'tab' => 'sections'])
            ->with('status', 'Section inserted — click "Add resource" to choose what goes in it.');
    }

    public function edit(Section $section): View
    {
        $this->authorize('update', $section);

        return view('teacher.sections.edit', ['section' => $section]);
    }

    public function update(UpdateSectionRequest $request, Section $section): RedirectResponse
    {
        $isPublished = $request->boolean('is_published', true);
        // Ticking "Published" at save time means "publish now" — clears any
        // pending schedule so it doesn't keep gating the section.
        $scheduledAt = $isPublished ? null : ($request->input('scheduled_at') ?: null);

        $section->update([
            'title' => $request->input('title'),
            'scheduled_at' => $scheduledAt,
            'sort_order' => $request->integer('sort_order'),
            'is_published' => $isPublished,
        ]);

        // Modal submits land back on the edit page with the modal closed.
        return redirect()
            ->route('courses.edit', [$section->course, 'tab' => 'sections'])
            ->with('status', 'Section updated.');
    }

    /**
     * Image-upload endpoint for the Quill rich-text editor (used in
     * Text-type sections). Returns JSON with the public URL so the editor
     * can insert <img> tags inline.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $path = $request->file('image')->store('section-text-images', 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    public function destroy(Section $section): RedirectResponse
    {
        $this->authorize('delete', $section);

        $course = $section->course;

        $section->delete();

        return redirect()
            ->route('courses.edit', [$course, 'tab' => 'sections'])
            ->with('status', 'Section deleted.');
    }
}
