<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreSectionRequest;
use App\Http\Requests\Teacher\UpdateSectionRequest;
use App\Models\Course;
use App\Models\Section;
use App\Support\PrivateFile;
use App\Support\CourseMedia;
use App\Models\SubmissionFile;
use App\Models\Submission;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Save whatever date the user typed as-is. scheduled_at doubles as a
        // "when this section is/was current" record — the student view uses
        // the latest past scheduled_at to decide which section auto-expands.
        // Admin who wants a plain published section with no date can just
        // clear the Available from field.
        $scheduledAt = $request->input('scheduled_at') ?: null;

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
            ->route('courses.edit', [$course, 'tab' => 'materials'])
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
        // Save whatever date the user typed as-is. scheduled_at doubles as a
        // "when this section is/was current" record — the student view uses
        // the latest past scheduled_at to decide which section auto-expands.
        // Admin who wants a plain published section with no date can just
        // clear the Available from field.
        $scheduledAt = $request->input('scheduled_at') ?: null;

        $section->update([
            'title' => $request->input('title'),
            'scheduled_at' => $scheduledAt,
            'sort_order' => $request->integer('sort_order'),
            'is_published' => $isPublished,
        ]);

        // Modal submits land back on the edit page with the modal closed.
        return redirect()
            ->route('courses.edit', [$section->course, 'tab' => 'materials'])
            ->with('status', 'Section updated.');
    }

    public function destroy(Section $section): RedirectResponse
    {
        $this->authorize('delete', $section);

        $course = $section->course;

        /*
         * forceDelete, not delete. Soft-deleting left the section's materials
         * behind: the FK cascade is a database constraint and only fires on a
         * real DELETE, so nothing downstream was removed and every file stayed
         * in storage referenced by nothing.
         *
         * The cascade reaches further than it first appears:
         *
         *   sections -> materials -> submissions -> submission_files
         *
         * so deleting a section containing an assignment also destroys the
         * work students submitted to it. That is the right outcome — the
         * assignment no longer exists — but it means the files have to be
         * collected BEFORE the rows disappear, because afterwards there is
         * nothing left to say which objects belonged to this section.
         */
        $materials = $section->materials()->withTrashed()->get();
        $embedded = [];
        $paths = [];

        foreach ($materials as $material) {
            if ($material->file_path) {
                $paths[] = $material->file_path;
            }
            $embedded = array_merge($embedded, CourseMedia::filenamesIn($material->body));
        }

        $paths = array_merge($paths, SubmissionFile::whereIn(
            'submission_id',
            Submission::whereIn('material_id', $materials->pluck('id'))->select('id'),
        )->pluck('file_path')->all());

        // Rows first: if this fails, nothing has been deleted from storage and
        // the section is still intact.
        $section->forceDelete();

        foreach ($paths as $path) {
            PrivateFile::forget($path);
        }

        CourseMedia::purgeUnreferenced($course->id, array_unique($embedded));

        return redirect()
            ->route('courses.edit', [$course, 'tab' => 'materials'])
            ->with('status', 'Section deleted.');
    }
}
