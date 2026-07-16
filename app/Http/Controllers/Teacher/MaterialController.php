<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreMaterialRequest;
use App\Http\Requests\Teacher\UpdateMaterialRequest;
use App\Models\Material;
use App\Models\Section;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'title' => $request->input('title'),
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
        ];

        if ($type === Material::TYPE_PDF) {
            $upload = $request->file('file');
            $courseId = $section->course_id;
            $name = Str::uuid().'.pdf';
            $path = $upload->storeAs("materials/{$courseId}/{$section->id}", $name);

            $data['file_path'] = $path;
            $data['file_size_bytes'] = $upload->getSize();
        } elseif ($type === Material::TYPE_TEXT) {
            $data['body'] = HtmlSanitizer::clean($request->input('body'));
        } elseif ($type === Material::TYPE_COUNTDOWN) {
            $data['target_date'] = $request->input('target_date');
        } else {
            $data['external_url'] = $request->input('external_url');
        }

        Material::create($data);

        return redirect()
            ->route('courses.edit', [$section->course, 'tab' => 'sections'])
            ->with('status', 'Resource added.');
    }

    public function edit(Material $material): View
    {
        $this->authorize('update', $material);

        return view('teacher.materials.edit', ['material' => $material]);
    }

    public function update(UpdateMaterialRequest $request, Material $material): RedirectResponse
    {
        $type = $request->input('type');
        $wasPdf = $material->type === Material::TYPE_PDF;

        // Reset all type-specific columns so changing type leaves nothing
        // stale; we'll re-populate the right ones below.
        $data = [
            'title' => $request->input('title'),
            'type' => $type,
            'sort_order' => $request->integer('sort_order'),
            'is_published' => $request->boolean('is_published', true),
            'file_path' => null,
            'file_size_bytes' => null,
            'external_url' => null,
            'body' => null,
            'target_date' => null,
        ];

        if ($type === Material::TYPE_PDF) {
            if ($request->hasFile('file')) {
                if ($material->file_path) {
                    Storage::delete($material->file_path);
                }
                $upload = $request->file('file');
                $courseId = $material->section->course_id;
                $name = Str::uuid().'.pdf';
                $data['file_path'] = $upload->storeAs("materials/{$courseId}/{$material->section_id}", $name);
                $data['file_size_bytes'] = $upload->getSize();
            } else {
                // No new upload — keep the existing PDF file.
                $data['file_path'] = $material->file_path;
                $data['file_size_bytes'] = $material->file_size_bytes;
            }
        } else {
            // Switched away from PDF — clean up the orphaned file.
            if ($wasPdf && $material->file_path) {
                Storage::delete($material->file_path);
            }

            if ($type === Material::TYPE_TEXT) {
                $data['body'] = HtmlSanitizer::clean($request->input('body'));
            } elseif ($type === Material::TYPE_COUNTDOWN) {
                $data['target_date'] = $request->input('target_date');
            } else {
                $data['external_url'] = $request->input('external_url');
            }
        }

        $material->update($data);

        return redirect()
            ->route('courses.edit', [$material->section->course, 'tab' => 'sections'])
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
            Storage::delete($material->file_path);
        }
        $material->delete();

        return redirect()
            ->route('courses.edit', [$course, 'tab' => 'sections'])
            ->with('status', 'Material deleted.');
    }
}
