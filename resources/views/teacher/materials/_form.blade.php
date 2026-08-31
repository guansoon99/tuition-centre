@props(['material' => null, 'action', 'method' => 'POST'])

@include('partials.course-video-uploader')

@php
    // The stored type may be 'page', but in the UI that is "Media" with a
    // checkbox ticked — so split the persisted type into a display type and an
    // asPage flag.
    //
    // A new material starts on PDF: the first pill in the row and the most
    // common thing a teacher adds. Only a fallback — an existing material opens
    // on its own type, and old() wins after a failed submit.
    $storedType = old('type', $material?->type ?? 'pdf');
    $displayType = $storedType === 'page' ? 'media' : $storedType;
    $asPage = $storedType === 'page';
@endphp
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-4"
      x-data="{ type: '{{ $displayType }}', asPage: {{ $asPage ? 'true' : 'false' }} }"
      x-init="
          const tryInit = () => initQuillEditor($refs.quillContainer, $refs.quillInput);
          const needsQuill = v => v !== 'countdown';
          if (needsQuill(type)) $nextTick(tryInit);
          $watch('type', v => { if (needsQuill(v)) $nextTick(tryInit); });
      ">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            Title <span class="font-normal text-slate-600">(optional)</span>
        </label>
        <input type="text" name="title"
               value="{{ old('title', $material?->title) }}"
               class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500" />
        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Type</label>
        <div class="flex flex-wrap gap-2">
            @foreach (['pdf' => 'PDF', 'external_link' => 'Link', 'media' => 'Media', 'assignment' => 'Assignment', 'countdown' => 'Countdown'] as $val => $lbl)
                <label class="inline-flex cursor-pointer items-center rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-slate-900 has-[:checked]:bg-slate-900 has-[:checked]:text-white">
                    <input type="radio" x-model="type" value="{{ $val }}" class="sr-only">
                    {{ $lbl }}
                </label>
            @endforeach
        </div>
        {{-- Hidden input carries the actual submitted type. When Media + "Open on
             a separate page" is checked, we submit 'page' instead of 'media'. --}}
        <input type="hidden" name="type"
               x-bind:value="type === 'media' && asPage ? 'page' : type">

        <label x-show="type === 'media'" x-cloak class="mt-2 flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" x-model="asPage" class="rounded border-slate-300">
            Open on a separate page
        </label>
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

    {{-- Body (shared Quill instance).

         Every type but Countdown has one, all the same height. For Media it
         *is* the material and is required; everywhere else it is optional —
         a description on an Assignment, a note under a PDF or Link row. --}}
    <div x-show="type !== 'countdown'" x-cloak>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            Body
            {{-- Required for Media and Page; optional everywhere else. --}}
            <span x-show="type !== 'media'" x-cloak class="font-normal text-slate-600">(optional)</span>
        </label>
        <div class="overflow-hidden rounded-md border border-slate-300">
            <div x-ref="quillContainer"
                 data-initial-html="{{ old('body', $material?->body) }}"
                 class="bg-white"></div>
        </div>
        {{-- Disabled so Countdown does not submit a stale body and wipe one
             that belongs to the type the teacher switched away from. --}}
        <textarea name="body" x-ref="quillInput"
                  x-bind:disabled="type === 'countdown'"
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

    {{-- Assignment settings — due date + per-assignment upload caps --}}
    <div x-show="type === 'assignment'" x-cloak class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="sm:col-span-3">
            <label class="mb-1 block text-sm font-medium text-slate-700">
                Due date <span class="font-normal text-slate-600">(optional)</span>
            </label>
            <input type="text" name="due_date" data-flatpickr
                   value="{{ old('due_date', $material?->due_date?->format('Y-m-d H:i')) }}"
                   placeholder="Y-m-d H:i"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
            @error('due_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Max file size (MB)</label>
            <input type="number" name="max_file_size_mb" min="1" max="{{ \App\Models\Material::MAX_FILE_SIZE_MB }}"
                   value="{{ old('max_file_size_mb', $material?->max_file_size_mb ?? \App\Models\Material::DEFAULT_MAX_FILE_SIZE_MB) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
            @error('max_file_size_mb')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Max files per student</label>
            <input type="number" name="max_files" min="1" max="50"
                   value="{{ old('max_files', $material?->max_files ?? 5) }}"
                   class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
            @error('max_files')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
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
    @vite('resources/js/flatpickr.js')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">
    <style>
        /* One editor height for every material type — see the note in
           admin/courses/_styles.blade.php for why this is not an arbitrary-value
           utility class. */
        .ql-editor { min-height: 280px; }
        .ql-editor img { max-width: 100%; height: auto; }
        .ql-editor .ql-align-center img { display: block; margin-left: auto; margin-right: auto; }
        .ql-editor .ql-align-right img  { display: block; margin-left: auto; margin-right: 0; }
        /* Toolbar wraps to the next row when it doesn't fit — no scrollbar,
           no vertical gap between wrapped rows. */
        .ql-toolbar.ql-snow { line-height: 0; padding: 4px 6px; }
        .ql-toolbar.ql-snow .ql-formats { display: inline-flex; flex-wrap: wrap; align-items: center; vertical-align: middle; margin: 0 8px 0 0; row-gap: 4px; }

        /* Link tooltip — pin to top of editor and restore input styling that
           Tailwind's preflight strips. Without this the popup lands near the
           cursor (can fall outside the modal) and the input reads as a black bar. */
        .ql-snow .ql-tooltip {
            left: 12px !important;
            top: 8px !important;
            transform: none !important;
            z-index: 50;
            background: #fff;
            border: 1px solid rgb(203 213 225);
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            padding: 6px 10px;
            font-size: 13px;
            color: rgb(51 65 85);
        }
        .ql-snow .ql-tooltip input[type=text] {
            display: inline-block;
            width: 220px;
            padding: 4px 8px;
            border: 1px solid rgb(203 213 225);
            border-radius: 4px;
            background: #fff;
            color: rgb(15 23 42);
            font-size: 13px;
            margin: 0 6px;
        }
        .ql-snow .ql-tooltip a { color: rgb(37 99 235); padding: 0 6px; cursor: pointer; font-weight: 500; }

        /* Header picker dropdown — restore line-height that our toolbar's
           `line-height: 0` (used for wrapping) strips out. Scoped to
           text-based pickers (:not(.ql-icon-picker)) so we don't stomp
           on icon pickers like Align, which need Quill's default 24×24
           dimensions for their SVG icons to render. */
        .ql-snow .ql-picker:not(.ql-icon-picker) .ql-picker-options {
            line-height: 1.4;
            padding: 4px 0;
            background: #fff;
            border: 1px solid rgb(203 213 225);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .ql-snow .ql-picker:not(.ql-icon-picker) .ql-picker-options .ql-picker-item {
            display: block;
            padding: 4px 12px;
            line-height: 1.4;
            cursor: pointer;
        }
        .ql-snow .ql-picker:not(.ql-icon-picker) .ql-picker-options .ql-picker-item:hover { color: rgb(37 99 235); }
    </style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            flatpickr('[data-flatpickr]', {
                enableTime: true, time_24hr: true, dateFormat: 'Y-m-d H:i',
                minuteIncrement: 5, allowInput: false, disableMobile: true,
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
                        container: [[
                            // All buttons in one .ql-formats group — no visual
                            // separators, wraps individually rather than by group.
                            { header: [1, 2, 3, false] },
                            'bold', 'italic', 'underline', 'strike',
                            { color: [] }, { background: [] },
                            { list: 'ordered' }, { list: 'bullet' },
                            { align: [] },
                            'blockquote',
                            'link', 'image', 'video',
                        ]],
                        handlers: {
                            image: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'image/jpeg,image/png,image/webp';
                                input.click();
                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;

                                    const ui = window.courseMediaOverlay(editor.root, 'Uploading image…');
                                    const form = new FormData();
                                    form.append('image', file);
                                    try {
                                        const res = await fetch('{{ route('course-media.upload-image', $course) }}', {
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
                                    } finally {
                                        ui.done();
                                    }
                                };
                            },
                            video: function () {
                                const input = document.createElement('input');
                                input.type = 'file';
                                input.accept = 'video/mp4,video/webm,video/quicktime';
                                input.click();
                                input.onchange = async () => {
                                    const file = input.files[0];
                                    if (!file) return;

                                    const ui = window.courseMediaOverlay(editor.root, 'Uploading video…');
                                    try {
                                        const url = await window.uploadCourseVideo({
                                            presign:  '{{ route('course-media.presign-video', $course) }}',
                                            register: '{{ route('course-media.register-video', $course) }}',
                                            upload:   '{{ route('course-media.upload-video', $course) }}',
                                            maxMb:    {{ \App\Http\Controllers\CourseMediaController::MAX_VIDEO_MB }},
                                        }, file, ui.progress);

                                        const range = editor.getSelection(true);
                                        const html = '<p><video controls src="' + url + '" style="max-width:100%;"></video></p>';
                                        editor.clipboard.dangerouslyPasteHTML(range.index, html, 'user');
                                    } catch (e) {
                                        alert('Video upload failed: ' + e.message);
                                    } finally {
                                        ui.done();
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
