{{--
    Where one student's submission stands.

    Shared by the student's own page and the teacher's grading modal, so the
    two cannot disagree about whether work is in, whether it is marked, or when
    it was last touched — which is exactly the kind of thing that drifts when
    the same table is written twice.

    Expects: $material, $submission (may be null)
    Optional: $canUpload — add the remove buttons and the upload form. The
              student's own page passes this; the teacher's modal does not,
              because a student's work is not the teacher's to change.

    The caller is responsible for including partials.detail-table-styles from
    a view the layout renders — see that file for why a modal fragment cannot.
--}}
@php
    $statusFiles = $submission?->files ?? collect();
    $statusHasSubmitted = $statusFiles->isNotEmpty();
    $statusIsPastDue = $material->isPastDue();

    // Never offer uploads past the deadline or once the work has been marked,
    // whatever the caller asked for. The routes refuse both anyway — this only
    // keeps the page from offering something that will be turned down.
    $statusCanUpload = ($canUpload ?? false)
        && ! $statusIsPastDue
        && ! $submission?->isGraded();

    $statusMaxFiles = $material->maxFiles();
    $statusSlotsLeft = max(0, $statusMaxFiles - $statusFiles->count());
@endphp

<h2 class="text-xl font-semibold text-slate-900">Submission Status</h2>

<div class="overflow-hidden rounded-lg border border-slate-200">
    <table class="detail-table">
        <tbody>
            <tr>
                <th scope="row">Submission status</th>
                <td>
                    @if ($statusHasSubmitted)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">
                            Submitted for grading
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
                            No submissions have been made yet
                        </span>
                    @endif
                </td>
            </tr>
            <tr>
                <th scope="row">Grading status</th>
                <td>
                    @if ($submission?->isGraded())
                        <span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-medium text-sky-800">
                            Graded
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
                            Not Graded
                        </span>
                    @endif
                </td>
            </tr>
            <tr>
                <th scope="row">Time remaining</th>
                <td>@include('partials.due-countdown')</td>
            </tr>
            <tr>
                <th scope="row">Last modified</th>
                <td>
                    {{-- Recorded on the submission itself rather than read off
                         the files, so removing every file leaves the record of
                         that removal behind. Blank until the first upload. --}}
                    {{ $submission?->last_modified_at?->format('Y-m-d H:i') ?: '—' }}
                </td>
            </tr>
            <tr>
                <th scope="row">File submission</th>
                <td>
                    {{-- No "nothing uploaded yet" line: where there is a picker
                         it sits directly below and says the same thing. Past
                         the deadline there is no picker, so that case keeps a
                         message rather than rendering blank. --}}
                    @if ($statusFiles->isEmpty())
                        @if ($statusIsPastDue)
                            <p class="text-sm italic text-slate-700">
                                Nothing was submitted before the deadline.
                            </p>
                        @elseif (! $statusCanUpload)
                            <p class="text-sm italic text-slate-700">Nothing submitted.</p>
                        @endif
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($statusFiles as $statusFile)
                                <li class="flex items-center justify-between gap-3 py-2">
                                    <div class="min-w-0">
                                        <a href="{{ route('submission-files.download', $statusFile) }}"
                                           class="truncate text-sm text-sky-700 hover:underline">
                                            {{ $statusFile->original_name }}
                                        </a>
                                        <p class="text-sm">
                                            {{ $statusFile->uploaded_at->format('Y-m-d H:i') }}
                                        </p>
                                    </div>
                                    @if ($statusCanUpload)
                                        <form method="POST" action="{{ route('submission-files.destroy', $statusFile) }}"
                                              onsubmit="return confirm('Remove {{ addslashes($statusFile->original_name) }}?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-sm text-red-600 hover:underline">
                                                Remove
                                            </button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- After the list, never instead of it: the student still
                         needs to see and download what was marked. Only on
                         their own page — the teacher has no controls here to
                         explain the absence of. --}}
                    @if (($canUpload ?? false) && ! $statusCanUpload && ! $statusIsPastDue && $submission?->isGraded())
                        <p class="mt-2 text-sm italic text-slate-700">
                            This assignment has been graded and can no longer be changed.
                        </p>
                    @endif

                    @if ($statusCanUpload)
                        {{-- Only when there is a list above to divide it from;
                             with no files it was a rule with nothing on one
                             side of it. --}}
                        @if ($statusFiles->isNotEmpty())
                            <hr class="my-4 border-t border-slate-200">
                        @endif

                        @if ($errors->any())
                            <div class="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                                <ul class="list-inside list-disc space-y-1">
                                    @foreach ($errors->all() as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($statusSlotsLeft > 0)
                            @include('partials.submission-uploader')
                        @else
                            <p class="rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                                You've reached the max of {{ $statusMaxFiles }} files. Remove a file above to upload another.
                            </p>
                        @endif
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
</div>
