{{--
    Body of the grading modal, fetched from submissions.grade-modal.

    Everything that used to sit inline on the roster row — the student's files,
    the grade and comment form, and the feedback files — lives here instead, so
    a row is one line about one student rather than four stacked controls.

    Both forms post normally and redirect, which reloads the page and closes
    the modal. That matches what grading already did before it moved in here.

    Expects: $submission (with student, files, feedbackFiles), $material
--}}
@php
    $files = $submission->files;
    $feedbackFiles = $submission->feedbackFiles;
@endphp

<div class="space-y-4">
    {{-- No name and no close icon here: the row this opens under already
         names the student, and its Grade button toggles to Close. --}}

    {{-- The same table the student sees, minus the controls: their work is
         theirs to add to and remove, not the teacher's.

         The styles for it are pushed by the roster page, not from here — a
         fragment fetched into a modal cannot reach the head stack. --}}
    @include('partials.submission-status-table')

    {{-- Feedback, laid out like the student's own Feedback section — same
         heading, same table — but with the fields editable.

         The grade form sits OUTSIDE the table and the inputs reach it by
         form="…". A <form> cannot legally wrap <tr> elements, and wrapping the
         whole table would nest the feedback upload form inside it, which is
         also invalid. The form attribute is how one table can serve two. --}}
    <h2 class="text-xl font-semibold text-slate-900">Feedback</h2>

    <form id="grade-form-{{ $submission->id }}"
          method="POST"
          action="{{ route('submissions.grade', $submission) }}"
          {{-- true: saving the grade closes the panel. Attaching a file does
               not, so those forms call this without the flag. --}}
          @submit.prevent="submitInPlace($event.target, true)">
        @csrf @method('PATCH')
    </form>

    <div class="overflow-hidden rounded-lg border border-slate-200">
        <table class="detail-table">
            <tbody>
                <tr>
                    <th scope="row">Grade</th>
                    <td>
                        <input type="text" name="grade" maxlength="32"
                               form="grade-form-{{ $submission->id }}"
                               value="{{ $submission->grade }}"
                               placeholder="e.g. 85"
                               class="w-32 rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Graded on</th>
                    {{-- Set when the grade is saved, so nothing to edit. --}}
                    <td>{{ $submission->graded_at?->format('Y-m-d H:i') ?: '—' }}</td>
                </tr>
                <tr>
                    <th scope="row">Comment</th>
                    <td>
                        <textarea name="comment" rows="3" maxlength="2000"
                                  form="grade-form-{{ $submission->id }}"
                                  placeholder="Optional"
                                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ $submission->comment }}</textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Feedback files</th>
                    <td>
                        @if ($files->isEmpty())
                            <p class="text-sm italic text-slate-700">
                                Nothing submitted, so there is nothing to respond to.
                            </p>
                        @else
                            @if ($feedbackFiles->isNotEmpty())
                                <ul class="mb-2 divide-y divide-slate-100">
                                    @foreach ($feedbackFiles as $feedbackFile)
                                        <li class="flex items-center justify-between gap-3 py-2">
                                            <a href="{{ route('feedback-files.download', $feedbackFile) }}"
                                               class="truncate text-sm text-sky-700 hover:underline">
                                                {{ $feedbackFile->original_name }}
                                            </a>
                                            {{-- submitInPlace lives on the modal
                                                 component wrapping this fragment;
                                                 Alpine resolves it up the scope
                                                 chain. --}}
                                            <form method="POST"
                                                  action="{{ route('feedback-files.destroy', $feedbackFile) }}"
                                                  @submit.prevent="confirm('Remove {{ addslashes($feedbackFile->original_name) }}?') && submitInPlace($event.target)">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-sm text-red-600 hover:underline">
                                                    Remove
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- One file at a time, sent on selection — the same
                                 flow the student side uses. $root, not $el: the
                                 expression runs on the <input>, and an input has
                                 no submit(). --}}
                            <form method="POST"
                                  action="{{ route('feedback-files.store', $submission) }}"
                                  enctype="multipart/form-data"
                                  x-data="{ busy: false }"
                                  @submit.prevent="submitInPlace($event.target)"
                                  data-no-spinner
                                  class="flex flex-wrap items-center gap-2">
                                @csrf
                                {{-- requestSubmit(), not submit(): the DOM
                                     submit() fires no submit event, so the
                                     handler above would never run and the page
                                     would navigate away as it used to. --}}
                                <input type="file" name="feedback_files[]"
                                       accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.ppt,.pptx,application/pdf,image/jpeg,image/png,image/webp"
                                       @change="if (! busy && $event.target.files.length) { busy = true; $root.requestSubmit(); }"
                                       class="block text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white" />
                                <span x-show="busy" x-cloak class="text-sm text-slate-600">Uploading…</span>
                                <noscript>
                                    <button type="submit"
                                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
                                        Send
                                    </button>
                                </noscript>
                            </form>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Close on the left, save on the right — the destructive-free exit
         first, the committing action last, which is the order these buttons
         sit in elsewhere in the app. --}}
    <div class="flex items-center justify-between gap-3">
        <button type="button"
                @click="openGradeFor = null"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
            Close
        </button>
        <button type="submit"
                form="grade-form-{{ $submission->id }}"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">
            Save grade
        </button>
    </div>
</div>
