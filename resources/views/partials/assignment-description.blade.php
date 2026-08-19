{{--
    An assignment's description, rendered the way the "open as separate page"
    view renders a page material — same .prose-page styles, same card.

    Before this it appeared only as a squeezed preview in the course material
    list and was not shown at all once the assignment was opened, so anything
    a teacher wrote below the first line or two was effectively invisible to
    students at the moment they were about to submit.

    Expects: $material
    Optional: $showDue — put the deadline and its status badge above the
              description, separated by a rule. The student view uses this
              (its title is a bare heading with no card to hold the deadline);
              the teacher view keeps its own header card and does not.
--}}
@include('partials.prose-page-styles')

@php
    $showDueDate = $showDue ?? false;

    $descriptionBody = $material->body ?? '';
    // Same emptiness test as partials/material-item: a body can be visually
    // empty while still carrying markup (Quill leaves "<p><br></p>" behind),
    // and can be non-empty with no text at all when it is just an image.
    $descriptionHasMedia = (bool) preg_match('/<(img|video|iframe|audio|source|embed)\b/i', $descriptionBody);
    $descriptionHasText = preg_replace(
        '/\s+/u', '', strip_tags(html_entity_decode($descriptionBody, ENT_QUOTES | ENT_HTML5))
    ) !== '';
    $hasDescription = $descriptionHasText || $descriptionHasMedia;
@endphp

{{-- The deadline is why this can render without a description: an assignment
     with a due date but no brief must still show when it is due. --}}
@if ($showDueDate || $hasDescription)
    <article class="prose-page rounded-lg border border-slate-200 bg-white p-6 text-slate-800">
        @if ($showDueDate)
            {{-- Divs, not paragraphs: .prose-page styles target p/h1/h2 and
                 would give these the body text's spacing. --}}
            {{-- The deadline only. How long is left, and whether anything has
                 been handed in, belong to the Submission Status card further
                 down — a live counter running in two places on one page is one
                 place too many. --}}
            <div class="space-y-1 text-sm text-slate-900">
                @if ($material->due_date)
                    <div class="text-base font-semibold">
                        Due: <span>{{ $material->due_date->format('Y-m-d H:i') }}</span>
                        @if ($material->isPastDue())
                            <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                Closed
                            </span>
                        @endif
                    </div>
                    <div class="text-base font-semibold">
                        Time remaining: @include('partials.due-countdown')
                    </div>
                @else
                    <div class="italic">No due date.</div>
                @endif
            </div>

            @if ($hasDescription)
                <hr class="my-4 border-t border-slate-200">
            @endif
        @endif

        @if ($hasDescription)
            {!! $descriptionBody !!}
        @endif
    </article>
@endif
