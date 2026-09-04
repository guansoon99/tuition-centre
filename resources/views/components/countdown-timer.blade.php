@props(['targetDate', 'title' => null])

{{-- Live countdown timer for a Material of type 'countdown'. Reads
     target_date and ticks every second on the client.

     The title is rendered here rather than by the caller so it sits inside
     the card with the clock it belongs to. --}}
<div class="rounded-md bg-gradient-to-br from-sky-400 via-indigo-500 to-purple-600 px-4 py-10 text-center text-white sm:px-8 sm:py-14"
     x-data="{
         targetMs: new Date('{{ $targetDate->toIso8601String() }}').getTime(),
         nowMs: Date.now(),
         get diff() { return Math.max(0, this.targetMs - this.nowMs); },
         get days() { return Math.floor(this.diff / 86400000); },
         get hours() { return Math.floor((this.diff % 86400000) / 3600000); },
         get minutes() { return Math.floor((this.diff % 3600000) / 60000); },
         get seconds() { return Math.floor((this.diff % 60000) / 1000); },
         pad(n) { return String(n).padStart(2, '0'); },
     }"
     x-init="setInterval(() => { nowMs = Date.now(); }, 1000)">

    @if (filled($title))
        <h3 class="mb-8 text-3xl font-bold leading-tight sm:text-5xl">{{ $title }}</h3>
    @endif

    @php
        // One box per unit rather than one per digit, so "06" reads as a
        // number instead of two tiles that happen to sit together.
        //
        // The boxes are fluid, not fixed. A fixed width overflowed the card:
        // this sits inside a section which is itself inside the page
        // container, so the space available is a good deal narrower than the
        // viewport, and four boxes plus separators plus gaps ran past the
        // edge with the labels clipped. Each column now takes an equal share
        // of whatever room there is and stops growing at 8rem, which is the
        // size the desktop layout wants anyway.
        //
        // aspect-square rather than a height, so a box stays a square at
        // every width without a second breakpoint to keep in step.
        //
        // tabular-nums, not font-mono: the numerals should look like the rest
        // of the page, but this ticks every second and proportional digits
        // change width as they change value, so the whole row would twitch
        // once a second without it.
        $col = 'min-w-0 max-w-[8rem] flex-1';
        $box = 'flex aspect-square w-full items-center justify-center rounded-xl bg-white text-2xl font-extrabold tabular-nums text-black shadow-sm sm:text-7xl';
        // Short forms below sm. "SECONDS" does not fit a column once the card
        // is inside a section inside the page container, and shaving a pixel
        // off the font size only moves the width at which it clips. truncate
        // stays as a backstop.
        $label = 'mt-2 truncate text-[10px] font-semibold uppercase text-white sm:mt-3 sm:text-base sm:tracking-wider';
        $units = ['days' => 'days', 'hours' => 'hrs', 'minutes' => 'min', 'seconds' => 'sec'];
        // Hidden on mobile: a fluid box has no fixed height to centre the
        // colon against, and the width it costs is the width that was
        // overflowing. The labels carry the meaning; the colons are
        // decoration. On desktop the box is a known 8rem, so the colon can
        // centre against the boxes rather than the boxes-plus-label column.
        $sep = 'hidden items-center font-bold text-white/70 sm:flex sm:h-32 sm:text-5xl';
    @endphp

    <div class="flex flex-nowrap items-start justify-center gap-2 sm:gap-4">
        <div class="{{ $col }}">
            <div class="{{ $box }}" x-text="pad(days)"></div>
            <p class="{{ $label }}">
                <span class="sm:hidden">days</span>
                <span class="hidden sm:inline">days</span>
            </p>
        </div>
        <div class="{{ $sep }}">:</div>
        <div class="{{ $col }}">
            <div class="{{ $box }}" x-text="pad(hours)"></div>
            <p class="{{ $label }}">
                <span class="sm:hidden">hrs</span>
                <span class="hidden sm:inline">hours</span>
            </p>
        </div>
        <div class="{{ $sep }}">:</div>
        <div class="{{ $col }}">
            <div class="{{ $box }}" x-text="pad(minutes)"></div>
            <p class="{{ $label }}">
                <span class="sm:hidden">min</span>
                <span class="hidden sm:inline">minutes</span>
            </p>
        </div>
        <div class="{{ $sep }}">:</div>
        <div class="{{ $col }}">
            <div class="{{ $box }}" x-text="pad(seconds)"></div>
            <p class="{{ $label }}">
                <span class="sm:hidden">sec</span>
                <span class="hidden sm:inline">seconds</span>
            </p>
        </div>
    </div>

    {{-- calendar.svg is a full-colour illustration, not a monochrome glyph,
         so it goes in as an <img> and keeps its own palette — no
         currentColor, and nothing to recolour on a theme change. --}}
    <div class="mt-8 inline-flex items-center gap-2 rounded-xl bg-white/20 px-4 py-2.5 sm:mt-10 sm:gap-3 sm:px-5 sm:py-3">
        <img src="{{ asset('images/icons/calendar.svg') }}" alt=""
             class="h-6 w-6 flex-shrink-0 sm:h-9 sm:w-9" />
        <span class="whitespace-nowrap text-base font-semibold tabular-nums sm:text-2xl">
            {{ $targetDate->format('Y-m-d H:i') }}
        </span>
    </div>
</div>
