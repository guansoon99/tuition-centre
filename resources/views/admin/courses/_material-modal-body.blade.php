{{--
    Body of the "Edit material" modal on the course Materials tab.

    Served on demand by Teacher\MaterialController::editModal rather than
    rendered inline for every material. A course with 72 materials used to
    ship 72 copies of this form in the page HTML (~1.8 MB) and, worse, spin
    up a Quill editor for each text/assignment one at page load.

    Rendered without a layout, so it must not rely on @push/@stack — the
    parent page already loads Quill, flatpickr and the Alpine bundle.

    Expects: $material
--}}
<div class="mb-4 flex items-center justify-between">
    <h3 class="text-lg font-semibold text-slate-900">Edit material</h3>
    <button type="button" @click="openMaterial = null"
            class="text-slate-400 hover:text-slate-600">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>
</div>

@php
    $matDisplayType = $material->type === 'page' ? 'media' : $material->type;
    $matAsPage = $material->type === 'page';
@endphp
<form method="POST" action="{{ route('materials.update', $material) }}" enctype="multipart/form-data"
      x-data="{ matType: '{{ $matDisplayType }}', matAsPage: {{ $matAsPage ? 'true' : 'false' }} }"
      x-init="
          const tryInit = () => initQuillEditor($refs.matQuillContainer_{{ $material->id }}, $refs.matQuillInput_{{ $material->id }});
          const needsQuill = v => v === 'media' || v === 'assignment';
          if (needsQuill(matType)) $nextTick(tryInit);
          $watch('matType', v => { if (needsQuill(v)) $nextTick(tryInit); });
      "
      class="space-y-4">
    @csrf @method('PATCH')

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            Title <span class="font-normal text-slate-600">(optional)</span>
        </label>
        <input type="text" name="title" value="{{ $material->title }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Type</label>
        <div class="flex flex-wrap gap-2">
            @foreach (['pdf' => 'PDF', 'external_link' => 'Link', 'media' => 'Media', 'assignment' => 'Assignment', 'countdown' => 'Countdown'] as $val => $lbl)
                <label class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white">
                    <input type="radio" x-model="matType" value="{{ $val }}" class="sr-only">
                    {{ $lbl }}
                </label>
            @endforeach
        </div>
        <input type="hidden" name="type"
               x-bind:value="matType === 'media' && matAsPage ? 'page' : matType">

        <label x-show="matType === 'media'" x-cloak class="mt-2 flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" x-model="matAsPage" class="rounded border-slate-300">
            Open on a separate page
        </label>
    </div>

    <div x-show="matType === 'pdf'" x-data="{ chosen: null }" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">PDF file</label>
        <input type="file" name="file" accept="application/pdf"
               @change="chosen = $event.target.files[0] || null"
               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
        <template x-if="chosen">
            <p class="mt-1 text-xs text-slate-500">
                Selected: <span x-text="chosen.name" class="font-mono"></span>
            </p>
        </template>
        @if ($material->file_path)
            <p class="mt-1 text-xs text-slate-500" x-show="!chosen">
                Current: {{ basename($material->file_path) }} — leave empty to keep.
            </p>
        @endif
    </div>

    <div x-show="matType === 'external_link'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">URL</label>
        <input type="url" name="external_url" value="{{ $material->external_url }}"
               placeholder="https://..."
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
    </div>

    <div x-show="matType === 'media' || matType === 'assignment'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            <span x-show="matType === 'media'">Body</span>
            <span x-show="matType === 'assignment'" x-cloak>Description</span>
        </label>
        <div class="overflow-hidden rounded-md border border-slate-300">
            <div x-ref="matQuillContainer_{{ $material->id }}"
                 data-initial-html="{{ $material->body }}"
                 class="min-h-[200px] bg-white"></div>
        </div>
        <textarea name="body"
                  x-ref="matQuillInput_{{ $material->id }}"
                  x-bind:disabled="matType !== 'media' && matType !== 'assignment'"
                  class="hidden">{{ $material->body }}</textarea>
    </div>

    <div x-show="matType === 'countdown'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">Target date</label>
        <input type="text" name="target_date" data-flatpickr
               value="{{ $material->target_date?->format('Y-m-d H:i') }}"
               placeholder="Y-m-d H:i"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
    </div>

    <div x-show="matType === 'assignment'" x-cloak class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="sm:col-span-3">
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Due date <span class="font-normal text-slate-600">(optional)</span>
            </label>
            <input type="text" name="due_date" data-flatpickr
                   value="{{ $material->due_date?->format('Y-m-d H:i') }}"
                   placeholder="Y-m-d H:i"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Max file size (MB)</label>
            <input type="number" name="max_file_size_mb" min="1" max="{{ \App\Models\Material::MAX_FILE_SIZE_MB }}"
                   value="{{ $material->max_file_size_mb ?? \App\Models\Material::DEFAULT_MAX_FILE_SIZE_MB }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Max files per student</label>
            <input type="number" name="max_files" min="1" max="50"
                   value="{{ $material->max_files ?? 5 }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1"
               @checked($material->is_published)>
        Published
    </label>

    <div class="flex items-center justify-between pt-2">
        <button type="button" @click="openMaterial = null"
                class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </button>
        <button type="submit"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            Save
        </button>
    </div>
</form>

<form method="POST" action="{{ route('materials.destroy', $material) }}"
      onsubmit="return confirm('Delete this material?');"
      class="mt-4 border-t border-slate-200 pt-4">
    @csrf @method('DELETE')
    <button type="submit" class="text-sm text-red-600 hover:underline">Delete material</button>
</form>
