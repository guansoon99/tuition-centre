@extends('layouts.app')

@section('title', $material->title ?: 'Assignment')

@section('content')
    @php
        $due = $material->due_date;
        $isPastDue = $material->isPastDue();
        $maxFiles = $material->max_files ?? 5;
        $files = $submission?->files ?? collect();
        $slotsLeft = max(0, $maxFiles - $files->count());
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        {{-- Breadcrumb back to the course --}}
        <div>
            <a href="{{ route('courses.show', $material->section->course) }}"
               class="text-base text-slate-900">
                &larr; Back To Course
            </a>
        </div>

        {{-- Header --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold text-slate-900">
                        {{ $material->title ?: 'Assignment' }}
                    </h1>
                    @if ($due)
                        <p class="mt-1 text-sm text-slate-900">
                            Due: <span class="font-mono">{{ $due->format('Y-m-d H:i') }}</span>
                        </p>
                    @else
                        <p class="mt-1 text-sm italic text-slate-900">No due date.</p>
                    @endif
                </div>

                @if ($due)
                    <div class="flex-shrink-0">
                        @if ($isPastDue)
                            <span class="inline-flex items-center rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-700">
                                Submissions closed
                            </span>
                        @else
                            <span x-data="{
                                    target: {{ $due->getTimestamp() * 1000 }},
                                    remaining: '',
                                    tick() {
                                        const diff = this.target - Date.now();
                                        if (diff <= 0) { this.remaining = 'Past due'; return; }
                                        const d = Math.floor(diff / 86400000);
                                        const h = Math.floor((diff % 86400000) / 3600000);
                                        const m = Math.floor((diff % 3600000) / 60000);
                                        this.remaining = (d > 0 ? d + 'd ' : '') + h + 'h ' + m + 'm';
                                    },
                                }"
                                x-init="tick(); setInterval(() => tick(), 30000)"
                                class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">
                                <span x-text="'In ' + remaining"></span>
                            </span>
                        @endif
                    </div>
                @endif
            </div>

        </div>

        {{-- Grade + comment card (shown once teacher grades) --}}
        @if ($submission?->isGraded())
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <div class="flex items-baseline gap-2">
                    <h2 class="text-sm font-semibold text-emerald-900">Grade:</h2>
                    <span class="text-lg font-bold text-emerald-900">{{ $submission->grade }}</span>
                </div>
                @if ($submission->comment)
                    <p class="mt-2 whitespace-pre-wrap text-sm text-emerald-900">{{ $submission->comment }}</p>
                @endif
                <p class="mt-2 text-xs text-emerald-700">
                    Graded on {{ $submission->graded_at->format('Y-m-d H:i') }}
                </p>
            </div>
        @endif

        {{-- Upload UI (hidden if past due) --}}
        @if (! $isPastDue)
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="mb-2 text-base font-semibold text-slate-900">Upload Files</h2>

                @if ($errors->any())
                    <div class="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                        <ul class="list-inside list-disc space-y-1">
                            @foreach ($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($slotsLeft > 0)
                    {{--
                        The form posts to submissions.upload, which proxies the
                        bytes through PHP. When the disk can presign, the Alpine
                        component below intercepts submit and sends each file
                        straight to R2 instead — and falls back to this same
                        form if that fails, which is what keeps a student on a
                        network that blocks the R2 endpoint able to submit.
                    --}}
                    <form method="POST" action="{{ route('submissions.upload', $material) }}"
                          enctype="multipart/form-data"
                          x-data="submissionUploader({
                              direct: {{ \App\Support\PrivateFile::canPresign() ? 'true' : 'false' }},
                              presignUrl: '{{ route('submissions.presign', $material) }}',
                              registerUrl: '{{ route('submissions.register', $material) }}',
                              csrf: '{{ csrf_token() }}',
                              maxBytes: {{ $material->maxFileSizeBytes() }},
                              maxMb: {{ $material->max_file_size_mb ?: \App\Models\Material::DEFAULT_MAX_FILE_SIZE_MB }},
                              slotsLeft: {{ $slotsLeft }},
                              accept: @js(\App\Models\Material::SUBMISSION_MIME_TYPES),
                          })"
                          @submit="onSubmit($event)"
                          {{-- Has its own per-file progress bar below; the
                               layout's generic submit spinner would only
                               duplicate it, and would still fire on the
                               proxied fallback path. --}}
                          data-no-spinner
                          class="space-y-3">
                        @csrf
                        <input type="file" name="files[]" multiple
                               accept="application/pdf,image/jpeg,image/png,image/webp"
                               @change="pick($event)"
                               {{-- Deliberately NOT disabled while busy: a disabled
                                    input is omitted from form submission, which would
                                    silently break the fallback path below. --}}
                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />

                        {{-- Client-side rejections, shown the moment a file is
                             picked rather than after a doomed upload. --}}
                        <template x-if="problems.length > 0">
                            <div class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                                <ul class="list-inside list-disc space-y-1">
                                    <template x-for="p in problems" :key="p">
                                        <li x-text="p"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        <template x-if="chosen.length > 0 && problems.length === 0">
                            <ul class="space-y-1 text-sm text-slate-600">
                                <template x-for="f in chosen" :key="f.name + f.size">
                                    <li>
                                        <span class="font-mono" x-text="f.name"></span>
                                        (<span x-text="human(f.size)"></span>)
                                    </li>
                                </template>
                            </ul>
                        </template>

                        <template x-if="busy">
                            <div class="space-y-1">
                                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full bg-slate-900 transition-all" :style="`width: ${progress}%`"></div>
                                </div>
                                <p class="text-sm text-slate-600">
                                    Uploading <span x-text="done + 1"></span> of <span x-text="chosen.length"></span>:
                                    <span class="font-mono" x-text="currentName"></span>
                                </p>
                            </div>
                        </template>

                        <button type="submit"
                                :disabled="chosen.length === 0 || problems.length > 0 || busy"
                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                            <span x-text="busy ? 'Uploading…' : 'Upload'"></span>
                        </button>
                    </form>
                @else
                    <p class="rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                        You've reached the max of {{ $maxFiles }} files. Remove a file below to upload another.
                    </p>
                @endif
            </div>
        @endif

        {{-- Submitted files --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-2 text-base font-semibold text-slate-900">
                My Files ({{ $files->count() }})
            </h2>

            @if ($files->isEmpty())
                <p class="text-sm italic text-slate-700">
                    @if ($isPastDue)
                        You did not submit any files before the deadline.
                    @else
                        No files uploaded yet.
                    @endif
                </p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($files as $file)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <a href="{{ route('submission-files.download', $file) }}"
                                   class="truncate text-sm text-sky-700 hover:underline">
                                    {{ $file->original_name }}
                                </a>
                                <p class="text-sm text-slate-900">
                                    {{ $file->uploaded_at->format('Y-m-d H:i') }}
                                </p>
                            </div>
                            @if (! $isPastDue)
                                <form method="POST" action="{{ route('submission-files.destroy', $file) }}"
                                      onsubmit="return confirm('Remove {{ addslashes($file->original_name) }}?');">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                            class="text-sm text-red-600 hover:underline">
                                        Remove
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
{{--
    Classic (non-module) script on purpose: it must define the component
    before Alpine walks the DOM. Alpine starts on DOMContentLoaded, and this
    runs during parsing, so the ordering holds.
--}}
<script>
function submissionUploader(config) {
    return {
        chosen: [],
        problems: [],
        busy: false,
        done: 0,
        progress: 0,
        currentName: '',

        human(bytes) {
            return bytes >= 1048576
                ? (bytes / 1048576).toFixed(1) + ' MB'
                : Math.max(1, Math.round(bytes / 1024)) + ' KB';
        },

        /*
         * Everything checkable without touching the network is checked here,
         * the instant a file is picked. The point is that a student learns
         * their 180MB scan is too big immediately, instead of after sitting
         * through an upload that was always going to be rejected.
         */
        pick(event) {
            this.chosen = Array.from(event.target.files);
            this.problems = [];

            if (this.chosen.length > config.slotsLeft) {
                this.problems.push(
                    `You picked ${this.chosen.length} files but only have ` +
                    `${config.slotsLeft} slot(s) left.`
                );
            }

            for (const f of this.chosen) {
                if (f.size > config.maxBytes) {
                    this.problems.push(
                        `"${f.name}" is ${this.human(f.size)} — the limit is ` +
                        `${config.maxMb} MB. If it's a scan, most scanner apps ` +
                        `have a "compress" or "smaller file size" option.`
                    );
                } else if (!config.accept.includes(f.type)) {
                    this.problems.push(
                        `"${f.name}" is not a PDF or an image (jpg/png/webp).`
                    );
                }
            }
        },

        onSubmit(event) {
            // No presigning available (dev, or a disk without it) — let the
            // form post normally and let PHP handle the upload.
            if (!config.direct) {
                return;
            }

            event.preventDefault();
            this.uploadAll();
        },

        async uploadAll() {
            if (this.busy || this.chosen.length === 0 || this.problems.length > 0) {
                return;
            }

            this.busy = true;
            this.done = 0;

            try {
                for (const file of this.chosen) {
                    this.currentName = file.name;
                    this.progress = 0;
                    await this.uploadOne(file);
                    this.done++;
                }
                window.location.reload();
            } catch (err) {
                if (err && err.rejected) {
                    // The server said no for a real reason. Falling back would
                    // just get the same answer more slowly.
                    this.problems = [err.message];
                    this.busy = false;
                    return;
                }

                /*
                 * Anything else — the R2 endpoint blocked by a school network,
                 * CORS, DNS, a 5xx — is a transport failure, not a verdict.
                 * Submit the form so the bytes go through this server instead.
                 * Slower and capped by Cloudflare/nginx/PHP, but it works.
                 */
                this.$el.submit();
            }
        },

        async uploadOne(file) {
            const signed = await this.post(config.presignUrl, {
                size: file.size,
                content_type: file.type,
            });

            await this.put(signed, file);

            await this.post(config.registerUrl, {
                key: signed.key,
                original_name: file.name,
            });
        },

        async post(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                },
                body: JSON.stringify(body),
            });

            if (res.ok) {
                return res.json();
            }

            // 4xx is a decision about this file; 5xx and network errors are
            // not, and should fall through to the proxy path.
            if (res.status >= 400 && res.status < 500) {
                const data = await res.json().catch(() => ({}));
                const message = data.message
                    || (data.errors && Object.values(data.errors)[0][0])
                    || 'That file was rejected.';
                throw { rejected: true, message };
            }

            throw new Error('presign/register failed: ' + res.status);
        },

        /*
         * XHR rather than fetch: only XHR reports upload progress, and a
         * student sending a large file over school wifi needs to see that
         * something is happening.
         */
        put(signed, file) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', signed.url);

                Object.entries(signed.headers || {}).forEach(([name, value]) => {
                    // The browser sets these itself and forbids overriding them.
                    if (/^(host|content-length)$/i.test(name)) return;
                    xhr.setRequestHeader(name, Array.isArray(value) ? value[0] : value);
                });

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        this.progress = Math.round((e.loaded / e.total) * 100);
                    }
                };
                xhr.onload = () => {
                    (xhr.status >= 200 && xhr.status < 300)
                        ? resolve()
                        : reject(new Error('PUT returned ' + xhr.status));
                };
                xhr.onerror = () => reject(new Error('network error during PUT'));
                xhr.ontimeout = () => reject(new Error('PUT timed out'));

                xhr.send(file);
            });
        },
    };
}
</script>
@endpush
