{{--
    The student's upload control.

    One file at a time, sent the moment it is picked — there is no Upload
    button. Extracted so the submission status table can carry it without that
    table needing to know how uploading works.

    Expects: $material
--}}
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
              types: @js(\App\Models\Material::SUBMISSION_TYPES),
          })"
          @submit="onSubmit($event)"
          {{-- Has its own per-file progress bar below; the
               layout's generic submit spinner would only
               duplicate it, and would still fire on the
               proxied fallback path. --}}
          data-no-spinner
          class="space-y-3">
        @csrf
        {{-- One file at a time, and it starts uploading as soon
             as it is picked — see pick() below. --}}
        <input type="file" name="files[]"
               accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.ppt,.pptx,application/pdf,image/jpeg,image/png,image/webp"
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

        <template x-if="busy">
            <div class="space-y-1">
                {{-- Only the direct path reports real progress.
                     The proxied one posts the form and the
                     browser takes over, so a bar there would sit
                     at zero and look stuck. --}}
                <template x-if="direct">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full bg-slate-900 transition-all" :style="`width: ${progress}%`"></div>
                    </div>
                </template>
                <p class="text-sm text-slate-600">
                    Uploading <span class="font-mono" x-text="currentName"></span>…
                </p>
            </div>
        </template>

        {{-- With JavaScript the upload starts on selection, so
             there is nothing left to click. Without it, Alpine
             never runs and this is the only way to submit. --}}
        <noscript>
            <button type="submit"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                Upload
            </button>
        </noscript>
    </form>
