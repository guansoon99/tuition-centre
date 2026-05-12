<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreMaterialRequest;
use App\Http\Requests\Teacher\UpdateMaterialRequest;
use App\Models\Material;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
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
        $data = [
            'section_id' => $section->id,
            'title' => $request->input('title'),
            'type' => $request->input('type'),
            'sort_order' => $request->integer('sort_order') ?: ($section->materials()->max('sort_order') + 1),
            'is_published' => $request->boolean('is_published', true),
            'published_at' => now(),
            'uploaded_by_user_id' => $request->user()->id,
        ];

        if ($request->input('type') === Material::TYPE_PDF) {
            $upload = $request->file('file');
            $courseId = $section->course_id;
            $name = Str::uuid().'.pdf';
            $path = $upload->storeAs("materials/{$courseId}/{$section->id}", $name);

            $data['file_path'] = $path;
            $data['file_size_bytes'] = $upload->getSize();
            $data['external_url'] = null;
        } else {
            $data['file_path'] = null;
            $data['file_size_bytes'] = null;
            $data['external_url'] = $request->input('external_url');
        }

        Material::create($data);

        return redirect()
            ->route('courses.show', $section->course)
            ->with('status', 'Material added.');
    }

    public function edit(Material $material): View
    {
        $this->authorize('update', $material);

        return view('teacher.materials.edit', ['material' => $material]);
    }

    public function update(UpdateMaterialRequest $request, Material $material): RedirectResponse
    {
        $data = [
            'title' => $request->input('title'),
            'type' => $request->input('type'),
            'sort_order' => $request->integer('sort_order'),
            'is_published' => $request->boolean('is_published', true),
        ];

        if ($request->input('type') === Material::TYPE_PDF) {
            if ($request->hasFile('file')) {
                if ($material->file_path) {
                    Storage::delete($material->file_path);
                }

                $upload = $request->file('file');
                $courseId = $material->section->course_id;
                $name = Str::uuid().'.pdf';
                $data['file_path'] = $upload->storeAs("materials/{$courseId}/{$material->section_id}", $name);
                $data['file_size_bytes'] = $upload->getSize();
            }
            $data['external_url'] = null;
        } else {
            $data['file_path'] = null;
            $data['file_size_bytes'] = null;
            $data['external_url'] = $request->input('external_url');
        }

        $material->update($data);

        return redirect()
            ->route('courses.edit', [$material->section->course, 'tab' => 'sections'])
            ->with('status', 'Material updated.');
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
