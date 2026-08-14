<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreMaterialRequest;
use App\Http\Requests\Teacher\UpdateMaterialRequest;
use App\Models\Material;
use App\Models\Section;
use App\Support\HtmlSanitizer;
use App\Support\CourseMedia;
use App\Support\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function create(Section $section): View
    {
        $this->authorize('create', [Material::class, $section]);

        return view('teacher.materials.create', ['section' => $section]);
    }

    public function store(StoreMaterialRequest $request, Section $section): RedirectResponse
    {
        $type = $request->input('type');

        $data = [
            'section_id' => $section->id,
            'title' => (string) $request->input('title'),
            'type' => $type,
            'sort_order' => $request->integer('sort_order') ?: ($section->materials()->max('sort_order') + 1),
            'is_published' => $request->boolean('is_published', true),
            'published_at' => now(),
            'uploaded_by_user_id' => $request->user()->id,
            'file_path' => null,
            'file_size_bytes' => null,
            'external_url' => null,
            'body' => null,
            'target_date' => null,
            'due_date' => null,
            'max_file_size_mb' => null,
            'max_files' => null,
        ];

        if ($type === Material::TYPE_PDF) {
            $upload = $request->file('file');
            $courseId = $section->course_id;
            $name = Str::uuid().'.pdf';
            $path = PrivateFile::storeAs($upload, CourseMedia::materialsFolder($courseId), $name);

            $data['file_path'] = $path;
            $data['file_size_bytes'] = $upload->getSize();
        } elseif ($type === Material::TYPE_TEXT || $type === Material::TYPE_PAGE) {
            $data['body'] = HtmlSanitizer::clean($request->input('body'));
        } elseif ($type === Material::TYPE_COUNTDOWN) {
            $data['target_date'] = $request->input('target_date');
        } elseif ($type === Material::TYPE_ASSIGNMENT) {
            $data['body'] = HtmlSanitizer::clean($request->input('body'));
            $data['due_date'] = $request->input('due_date') ?: null;
            $data['max_file_size_mb'] = $request->integer('max_file_size_mb') ?: Material::DEFAULT_MAX_FILE_SIZE_MB;
            $data['max_files'] = $request->integer('max_files') ?: Material::DEFAULT_MAX_FILES;
        } else {
            $data['external_url'] = $request->input('external_url');
        }

        Material::create($data);

        return redirect()
            ->route('courses.edit', [$section->course, 'tab' => 'materials'])
            ->with('status', 'Resource added.');
    }

    public function edit(Material $material): View
    {
        $this->authorize('update', $material);

        return view('teacher.materials.edit', ['material' => $material]);
    }

    /**
     * Body of the edit-material modal on the course Materials tab, fetched
     * when the user actually opens it.
     *
     * It used to be rendered inline for every material on the page. A course
     * with 72 materials shipped ~1.8 MB of HTML and initialised a Quill
     * editor per text/assignment material at page load, all of it hidden.
     *
     * Returns a bare fragment — no layout — for the caller to inject.
     */
    public function editModal(Material $material): View
    {
        $this->authorize('update', $material);

        return view('admin.courses._material-modal-body', ['material' => $material]);
    }

    /**
     * Body of the "Add Resource" modal, fetched when opened.
     *
     * Same reasoning as editModal(): this was rendered once per section, and
     * the form it wraps pushes a few hundred lines of Quill styles/scripts
     * that aren't @once-guarded, so they were duplicated per section too.
     */
    public function createModal(Section $section): View
    {
        $this->authorize('create', [Material::class, $section]);

        return view('admin.courses._material-create-modal-body', ['section' => $section]);
    }

    public function update(UpdateMaterialRequest $request, Material $material): RedirectResponse
    {
        $type = $request->input('type');
        $wasPdf = $material->type === Material::TYPE_PDF;

        // Reset all type-specific columns so changing type leaves nothing
        // stale; we'll re-populate the right ones below.
        $data = [
            'title' => (string) $request->input('title'),
            'type' => $type,
            // sort_order is managed exclusively via drag-and-drop; only accept
            // an explicit value if one was posted, otherwise preserve the row.
            'sort_order' => $request->has('sort_order')
                ? $request->integer('sort_order')
                : $material->sort_order,
            'is_published' => $request->boolean('is_published', true),
            'file_path' => null,
            'file_size_bytes' => null,
            'external_url' => null,
            'body' => null,
            'target_date' => null,
            'due_date' => null,
            'max_file_size_mb' => null,
            'max_files' => null,
        ];

        // Superseded PDF is noted and deleted only after the row is saved —
        // see the note in SettingsController::update. Deleting first loses the
        // existing file if storing the replacement fails.
        $replaced = null;

        if ($type === Material::TYPE_PDF) {
            if ($request->hasFile('file')) {
                $replaced = $material->file_path;
                $upload = $request->file('file');
                $courseId = $material->section->course_id;
                $name = Str::uuid().'.pdf';
                $data['file_path'] = PrivateFile::storeAs($upload, CourseMedia::materialsFolder($courseId), $name);
                $data['file_size_bytes'] = $upload->getSize();
            } else {
                // No new upload — keep the existing PDF file.
                $data['file_path'] = $material->file_path;
                $data['file_size_bytes'] = $material->file_size_bytes;
            }
        } else {
            // Switched away from PDF — the file is no longer referenced.
            if ($wasPdf && $material->file_path) {
                $replaced = $material->file_path;
            }

            if ($type === Material::TYPE_TEXT || $type === Material::TYPE_PAGE) {
                $data['body'] = HtmlSanitizer::clean($request->input('body'));
            } elseif ($type === Material::TYPE_COUNTDOWN) {
                $data['target_date'] = $request->input('target_date');
            } elseif ($type === Material::TYPE_ASSIGNMENT) {
                $data['body'] = HtmlSanitizer::clean($request->input('body'));
                $data['due_date'] = $request->input('due_date') ?: null;
                $data['max_file_size_mb'] = $request->integer('max_file_size_mb') ?: Material::DEFAULT_MAX_FILE_SIZE_MB;
                $data['max_files'] = $request->integer('max_files') ?: Material::DEFAULT_MAX_FILES;
            } else {
                $data['external_url'] = $request->input('external_url');
            }
        }

        // Media the teacher removed from the body while editing. Worked out
        // before the update, since afterwards the old body is gone and the
        // only record of what it referenced would be the object itself.
        $dropped = array_diff(
            CourseMedia::filenamesIn($material->body),
            CourseMedia::filenamesIn($data['body'] ?? null),
        );

        $material->update($data);

        PrivateFile::forget($replaced);

        if ($dropped !== []) {
            CourseMedia::purgeUnreferenced(
                $material->section->course_id,
                $dropped,
                $material->id,
            );
        }

        return redirect()
            ->route('courses.edit', [$material->section->course, 'tab' => 'materials'])
            ->with('status', 'Material updated.');
    }

    /**
     * Persist a new drag-and-drop order for the materials in a section.
     * Expects `ids` = the material IDs in their new visual order.
     */
    public function reorder(Request $request, Section $section): JsonResponse
    {
        $this->authorize('create', [Material::class, $section]);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        // Only reorder rows that actually belong to this section — guards
        // against a crafted payload with material IDs from a different
        // section (or non-existent ones).
        $valid = Material::whereIn('id', $data['ids'])
            ->where('section_id', $section->id)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $valid, $section) {
            $order = 1;
            foreach ($data['ids'] as $id) {
                if (! in_array((int) $id, $valid, true)) {
                    continue;
                }
                Material::where('id', $id)
                    ->where('section_id', $section->id)
                    ->update(['sort_order' => $order++]);
            }
        });

        return response()->json(['ok' => true, 'count' => count($valid)]);
    }

    public function destroy(Material $material): RedirectResponse
    {
        $this->authorize('delete', $material);

        $course = $material->section->course;

        if ($material->file_path) {
            PrivateFile::forget($material->file_path);
        }

        // Images and video embedded in the body are files too — they were
        // just never cleaned up, because unlike file_path they live inside
        // the HTML rather than in a column. Deleted here for the same reason
        // and at the same moment as the PDF above.
        CourseMedia::purgeUnreferenced(
            $course->id,
            CourseMedia::filenamesIn($material->body),
            $material->id,
        );

        $material->delete();

        return redirect()
            ->route('courses.edit', [$course, 'tab' => 'materials'])
            ->with('status', 'Material deleted.');
    }
}
