@props(['material' => null, 'action', 'method' => 'POST'])

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-4"
      x-data="{ type: '{{ old('type', $material?->type ?? 'text') }}' }"
      x-init="
          const tryInit = () => initQuillEditor($refs.quillContainer, $refs.quillInput);
          if (type === 'text') $nextTick(tryInit);
          $watch('type', v => { if (v === 'text') $nextTick(tryInit); });
      ">
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
        <div class="flex flex-wrap gap-2">
            @foreach (['text' => 'Text', 'pdf' => 'PDF', 'external_link' => 'Link', 'countdown' => 'Countdown'] as $val => $lbl)
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white">
                    <input type="radio" name="type" value="{{ $val }}"
                           x-model="type"
                           class="h-4 w-4 accent-slate-900">
                    {{ $lbl }}
                </label>
            @endforeach
        </div>
    </div>

    {{-- PDF --}}
    <div x-show="type === 'pdf'" x-data="{ chosen: null }" x-cloak>
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

    {{-- External link --}}
    <div x-show="type === 'external_link'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">URL</label>
        <input type="url" name="external_url"
               value="{{ old('external_url', $material?->external_url) }}"
               placeholder="https://..."
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
        @error('external_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- Text block (Quill rich text editor) --}}
    <div x-show="type === 'text'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">Body</label>
        <div class="overflow-hidden rounded-md border border-slate-300">
            <div x-ref="quillContainer"
                 data-initial-html="{{ old('body', $material?->body) }}"
                 class="min-h-[280px] bg-white"></div>
        </div>
        <textarea name="body" x-ref="quillInput"
                  x-bind:disabled="type !== 'text'"
                  class="hidden">{{ old('body', $material?->body) }}</textarea>
        @error('body')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- Countdown target date --}}
    <div x-show="type === 'countdown'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">Target date</label>
        <input type="text" name="target_date" data-flatpickr
               value="{{ old('target_date', $material?->target_date?->format('Y-m-d H:i')) }}"
               placeholder="Y-m-d H:i"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
        <p class="mt-1 text-xs text-slate-500">The countdown will tick down to this moment.</p>
        @error('target_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1"
               @checked(old('is_published', $material?->is_published ?? true))
               class="rounded border-slate-300">
        Published
    </label>

    <div class="flex gap-3">
        <a href="{{ url()->previous() }}"
           class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700">
            Cancel
        </a>
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            {{ $material ? 'Save' : 'Add resource' }}
        </button>
    </div>
</form>

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">
    <style>
        .ql-editor img { max-width: 100%; height: auto; }
        .ql-editor .ql-align-center img { display: block; margin-left: auto; margin-right: auto; }
        .ql-editor .ql-align-right img  { display: block; margin-left: auto; margin-right: 0; }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', {
                enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i',
                minuteIncrement: 5, allowInput: false,
            });
        });

        // Same idempotent Quill initializer used by the section edit modal.
        window.initQuillEditor = window.initQuillEditor || function (container, mirrorInput) {
            if (!container || container.dataset.quillReady === '1') return;
            container.dataset.quillReady = '1';
            const editor = new Quill(container, {
                theme: 'snow',
                placeholder: 'Write something…',
                modules: {
                    toolbar: {
                        container: [
                            [{ header: [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ list: 'ordered' }, { list: 'bullet' }],
                            [{ align: [] }],
                            ['blockquote'],
                            ['link', 'image'],
                        ],
                        handlers: {
                            image: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'image/*';
                                input.click();
                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;
                                    const form = new FormData();
                                    form.append('image', file);
                                    try {
                                        const res = await fetch('{{ route('sections.upload-image') }}', {
                                            method: 'POST',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                'Accept': 'application/json',
                                            },
                                            body: form,
                                        });
                                        if (!res.ok) throw new Error('Upload failed (' + res.status + ')');
                                        const data = await res.json();
                                        const range = editor.getSelection(true);
                                        editor.insertEmbed(range.index, 'image', data.url, 'user');
                                        editor.setSelection(range.index + 1);
                                    } catch (e) {
                                        alert('Image upload failed: ' + e.message);
                                    }
                                };
                            },
                        },
                    },
                },
            });
            const initial = container.dataset.initialHtml || mirrorInput.value || '';
            if (initial) editor.clipboard.dangerouslyPasteHTML(initial);
            editor.on('text-change', () => { mirrorInput.value = editor.root.innerHTML; });
        };
    </script>
@endpush
