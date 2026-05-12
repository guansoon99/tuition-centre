@props(['material' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-4"
      x-data="{ type: '{{ old('type', $material?->type ?? 'pdf') }}' }">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Title</label>
        <input type="text" name="title" required
               value="{{ old('title', $material?->title) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Type</label>
        <select name="type" x-model="type"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
            <option value="pdf">PDF (uploaded)</option>
            <option value="external_link">External link</option>
            <option value="video_link">Video link (Google Drive)</option>
        </select>
    </div>

    <div x-show="type === 'pdf'" x-data="{ chosen: null }">
        <label class="mb-1 block text-sm font-medium text-slate-700">PDF file</label>
        <input type="file" name="file" accept="application/pdf"
               @change="chosen = $event.target.files[0] || null"
               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />

        <template x-if="chosen">
            <p class="mt-1 text-xs text-slate-500">
                Selected: <span x-text="chosen.name" class="font-mono"></span>
                (<span x-text="Math.round(chosen.size / 1024) + ' KB'"></span>)
            </p>
        </template>

        @if ($material?->file_path)
            <p class="mt-1 text-xs text-slate-500" x-show="!chosen">
                Current: {{ basename($material->file_path) }} ({{ number_format(($material->file_size_bytes ?? 0) / 1024) }} KB) — leave empty to keep.
            </p>
        @endif
        @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div x-show="type !== 'pdf'">
        <label class="mb-1 block text-sm font-medium text-slate-700">URL</label>
        <input type="url" name="external_url"
               value="{{ old('external_url', $material?->external_url) }}"
               placeholder="https://..."
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
        @error('external_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Sort order</label>
        <input type="number" name="sort_order" min="0"
               value="{{ old('sort_order', $material?->sort_order) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1"
               @checked(old('is_published', $material?->is_published ?? true))
               class="rounded border-slate-300">
        Published
    </label>

    <div class="flex gap-3">
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $material ? 'Save changes' : 'Add material' }}
        </button>
        <a href="{{ url()->previous() }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
    </div>
</form>
