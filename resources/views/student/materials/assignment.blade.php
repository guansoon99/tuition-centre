@extends('layouts.app')

@section('title', $material->title ?: 'Assignment')

@section('content')
    @php
        $isPastDue = $material->isPastDue();
        $maxFiles = $material->max_files ?? 5;
        $files = $submission?->files ?? collect();
        $slotsLeft = max(0, $maxFiles - $files->count());

        // A submission row with no files is an abandoned upload, which is not
        // a submission — the same rule the teacher's list and the status
        // export use, so all three agree about who has handed in.
        $hasSubmitted = $files->isNotEmpty();
        $feedbackFiles = $submission?->feedbackFiles ?? collect();
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        {{-- Breadcrumb back to the course --}}
        <div>
            <a href="{{ route('courses.show', $material->section->course) }}"
               class="text-base text-slate-900">
                &larr; Back To Course
            </a>
        </div>

        @include('partials.assignment-title')

        {{-- Deadline, a rule, then what the student has been asked to do. --}}
        @include('partials.assignment-description', ['showDue' => true])

        @include('partials.detail-table-styles')

        {{-- Shared with the teacher's grading modal, so both sides describe
             one submission the same way. canUpload is what makes this the
             student's copy: the files are theirs to add to and remove. --}}
        @include('partials.submission-status-table', ['canUpload' => true])

        {{-- Feedback, in the same table as the status above it.
             Shown once there is feedback of any kind — a mark, or a file
             returned. Keying it on the mark alone hid files a teacher sent
             back before deciding a grade, which is a normal way to work.
             Still hidden entirely when there is neither, so an unmarked
             assignment carries no empty section. --}}
        @if ($submission && ($submission->isGraded() || $feedbackFiles->isNotEmpty()))
            <h2 class="text-xl font-semibold text-slate-900">Feedback</h2>

            <div class="detail-card--feedback overflow-hidden rounded-lg">
                <table class="detail-table detail-table--feedback">
                    <tbody>
                        <tr>
                            <th scope="row">Grade</th>
                            <td>{{ $submission->grade ?: '—' }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Graded on</th>
                            {{-- Nullable now: files can arrive before a mark. --}}
                            <td>{{ $submission->graded_at?->format('Y-m-d H:i') ?: '—' }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Comment</th>
                            {{-- Always shown, so the section reads as a fixed set
                                 of fields rather than rows that come and go.
                                 pre-wrap because teachers type across lines and
                                 the breaks otherwise collapse into one. --}}
                            <td class="whitespace-pre-wrap">{{ $submission->comment ?: '—' }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Feedback files</th>
                            <td>
                                @if ($feedbackFiles->isEmpty())
                                    —
                                @else
                                    <ul class="divide-y divide-slate-100">
                                        @foreach ($feedbackFiles as $feedbackFile)
                                            <li class="py-2 first:pt-0 last:pb-0">
                                                <a href="{{ route('feedback-files.download', $feedbackFile) }}"
                                                   class="text-sky-700 hover:underline">
                                                    {{ $feedbackFile->original_name }}
                                                </a>
                                                <p class="text-sm">
                                                    {{ $feedbackFile->uploaded_at->format('Y-m-d H:i') }}
                                                </p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
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
        // Exposed so the markup can tell a real progress bar from a form post.
        direct: config.direct,

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
            if (this.busy) {
                return;
            }

            this.chosen = Array.from(event.target.files);
            this.problems = [];

            for (const f of this.chosen) {
                if (f.size > config.maxBytes) {
                    this.problems.push(
                        `"${f.name}" is ${this.human(f.size)} — the limit is ` +
                        `${config.maxMb} MB. If it's a scan, most scanner apps ` +
                        `have a "compress" or "smaller file size" option.`
                    );
                } else if (!this.mimeFor(f)) {
                    this.problems.push(
                        `"${f.name}" is not a PDF, image, Word or PowerPoint file.`
                    );
                }
            }

            // Picking the file IS the action — there is no Upload button to
            // press. Only start once the checks above have passed, so a file
            // that was never going to be accepted costs no upload at all.
            if (this.chosen.length > 0 && this.problems.length === 0) {
                this.start();
            }
        },

        /*
         * Begin the upload the student just triggered by choosing a file.
         *
         * Without presigning there is nothing to do here but post the form and
         * let PHP take the bytes — the same fallback the direct path drops to
         * when R2 is unreachable. Note this is the DOM submit(), which fires no
         * submit event, so onSubmit() below does not run twice.
         */
        start() {
            if (!config.direct) {
                this.busy = true;
                this.currentName = this.chosen[0]?.name ?? '';
                // $root, not $el: Alpine binds $el to the element the
                // expression ran on, which here is the <input> that fired
                // @change, and an input has no submit(). $root is the
                // component's root element — the form.
                this.$root.submit();

                return;
            }

            this.uploadAll();
        },

        /*
         * What we will tell the server this file is.
         *
         * Deliberately the extension and not file.type: browsers disagree
         * about Office documents (Chrome names them, some report an empty
         * string), and an empty content_type fails the presign whitelist —
         * the upload would be rejected before it started. The server does not
         * trust this value either way; it re-checks the stored bytes.
         */
        mimeFor(file) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();

            return config.types[ext] || null;
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
                this.$root.submit();
            }
        },

        async uploadOne(file) {
            const signed = await this.post(config.presignUrl, {
                size: file.size,
                content_type: this.mimeFor(file),
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
