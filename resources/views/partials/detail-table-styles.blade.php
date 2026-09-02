{{--
    Styling for the label/value tables on the assignment pages.

    Plain CSS, not Tailwind utilities: the compiled stylesheet only contains
    classes that existed when it was last built, so a new utility — an
    arbitrary value especially — renders as nothing at all until someone runs
    the asset build. The .prose-section and .prose-page blocks solve the same
    problem the same way.

    Must be included by a view the LAYOUT renders. A fragment fetched into a
    modal cannot push to the head stack — that stack was already rendered when
    the page loaded, so the push is silently discarded and the table arrives
    unstyled.

    @once so several includes on one page emit it only once.
--}}
@once
@push('head')
    <style>
        .detail-table { width: 100%; border-collapse: collapse; background: #fff; }
        .detail-table th,
        .detail-table td { padding: 0.625rem 1rem; text-align: left; vertical-align: top; font-size: 0.875rem; }
        .detail-table th { width: 14rem; font-weight: 600; color: rgb(51 65 85); background: rgb(248 250 252); border-right: 1px solid rgb(226 232 240); }
        .detail-table td { color: rgb(15 23 42); }
        .detail-table tr + tr th,
        .detail-table tr + tr td { border-top: 1px solid rgb(226 232 240); }
        /* The marking card reads as a different kind of thing from the status
           table above it — it is the teacher talking back — so it gets a warm
           ground instead of the neutral one. Note the wording here: this
           block ships inside every page's head, so a word used in a comment
           is a word present in the HTML, and tests that assert a section is
           absent look for exactly that. Borders are set here rather than
           left to a Tailwind class on the wrapper: both would be a single
           class selector, so which wins would come down to stylesheet order
           in the head, which is not something to rely on for a colour. */
        .detail-card--feedback { border: 1px solid rgb(254 240 138); }
        .detail-table--feedback { background: rgb(254 253 242); }
        .detail-table--feedback th { background: rgb(254 252 232); border-right-color: rgb(254 240 138); }
        .detail-table--feedback tr + tr th,
        .detail-table--feedback tr + tr td { border-top-color: rgb(254 240 138); }

        /* Narrow screens: a 14rem label column leaves nothing for the value. */
        @media (max-width: 640px) {
            .detail-table th,
            .detail-table td { display: block; width: auto; border-right: 0; }
            .detail-table tr + tr th { border-top: 1px solid rgb(226 232 240); }
            .detail-table tr + tr td { border-top: 0; }
            .detail-table--feedback tr + tr th { border-top-color: rgb(254 240 138); }
        }
    </style>
@endpush
@endonce
