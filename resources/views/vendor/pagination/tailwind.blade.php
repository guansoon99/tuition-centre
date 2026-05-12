@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation"
         class="flex flex-col items-center gap-3 sm:flex-row sm:items-center sm:justify-between">

        {{-- Result summary --}}
        <p class="text-xs text-gray-700">
            Showing
            @if ($paginator->firstItem())
                <span class="font-semibold">{{ $paginator->firstItem() }}</span>
                to
                <span class="font-semibold">{{ $paginator->lastItem() }}</span>
            @else
                {{ $paginator->count() }}
            @endif
            of
            <span class="font-semibold">{{ $paginator->total() }}</span> results
        </p>

        {{-- Page buttons --}}
        <div class="inline-flex items-center gap-1">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span aria-disabled="true"
                      class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-slate-300">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   aria-label="Previous"
                   class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="inline-flex h-8 min-w-[2rem] items-center justify-center px-2 text-sm text-slate-400">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                  class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md bg-slate-900 px-2 text-sm font-semibold text-white">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}"
                               aria-label="Go to page {{ $page }}"
                               class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-md border border-slate-200 bg-white px-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   aria-label="Next"
                   class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            @else
                <span aria-disabled="true"
                      class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-slate-300">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </span>
            @endif
        </div>
    </nav>
@endif
